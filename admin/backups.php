<?php

/**
 * Database Backup Management
 * 
 * This file handles database backup operations for administrators
 * including creating, downloading, and restoring backups.
 */

// Ensure this is accessed only by authenticated administrators
if (!defined('BASEPATH')) exit('No direct script access allowed');

class Backups extends CI_Controller {
    
    public function __construct() {
        parent::__construct();
        
        // Check if user is logged in and is an admin
        if(!$this->session->userdata('logged_in')) {
            redirect('admin/login');
        }
        
        // Check admin permissions
        if(!hasPermission('backup_manage')) {
            flash_message('error', 'You do not have permission to access this section.');
            redirect('admin/dashboard');
        }
        
        $this->load->helper('file');
        $this->load->helper('download');
        $this->load->dbutil();
        $this->backupPath = FCPATH . 'application/backups/';
        
        // Create backup directory if it doesn't exist
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
        
        // Create .htaccess to prevent direct access to backup files
        $htaccess = "deny from all";
        if (!file_exists($this->backupPath . '.htaccess')) {
            file_put_contents($this->backupPath . '.htaccess', $htaccess);
        }
    }
    
    /**
     * Main view for backup management
     */
    public function index() {
        $data = array();
        $data['page_title'] = 'Database Backups';
        $data['backups'] = $this->getBackupFiles();
        
        // Get database size
        $db_size = $this->getDatabaseSize();
        $data['db_size'] = $this->formatFileSize($db_size);
        
        // Check if backup directory is writable
        $data['is_writable'] = is_writable($this->backupPath);
        
        $this->load->view('admin/header', $data);
        $this->load->view('admin/backups/index', $data);
        $this->load->view('admin/footer');
    }
    
    /**
     * Create a new database backup
     */
    public function create() {
        // Log this action
        log_activity('Backup creation initiated by ' . $this->session->userdata('username'));
        
        try {
            // Set backup preferences
            $backup_name = 'db-backup-' . date('Y-m-d-H-i-s') . '.sql';
            
            $prefs = array(
                'format'        => 'txt',        // sql or zip
                'filename'      => $backup_name, // File name
                'add_drop'      => TRUE,         // Add DROP TABLE statements
                'add_insert'    => TRUE,         // Add INSERT statements
                'newline'       => "\n",         // Newline character
                'foreign_key_checks' => FALSE,   // Disable foreign key checks
            );
            
            // Backup your entire database and assign it to a variable
            $backup = $this->dbutil->backup($prefs);
            
            // Load the file helper and write the file to your server
            if (!write_file($this->backupPath . $backup_name, $backup)) {
                flash_message('error', 'Unable to write backup file to disk. Check directory permissions.');
                redirect('admin/backups');
                return;
            }
            
            // Success message
            flash_message('success', 'Database backup created successfully.');
            
            // Log successful backup
            log_activity('Database backup created: ' . $backup_name);
            
            // Redirect back to backups page
            redirect('admin/backups');
        } catch (Exception $e) {
            flash_message('error', 'Backup failed: ' . $e->getMessage());
            log_activity('Backup failed: ' . $e->getMessage(), 'error');
            redirect('admin/backups');
        }
    }
    
    /**
     * Download a backup file
     * 
     * @param string $filename Backup filename
     */
    public function download($filename = '') {
        // Validate filename to prevent directory traversal
        $filename = basename($filename);
        $file_path = $this->backupPath . $filename;
        
        if (!file_exists($file_path)) {
            flash_message('error', 'Backup file not found.');
            redirect('admin/backups');
            return;
        }
        
        // Log download
        log_activity('Backup downloaded: ' . $filename);
        
        // Force download
        force_download($filename, file_get_contents($file_path));
    }
    
    /**
     * Delete a backup file
     * 
     * @param string $filename Backup filename
     */
    public function delete($filename = '') {
        // Validate filename to prevent directory traversal
        $filename = basename($filename);
        $file_path = $this->backupPath . $filename;
        
        if (!file_exists($file_path)) {
            flash_message('error', 'Backup file not found.');
            redirect('admin/backups');
            return;
        }
        
        // Add CSRF protection
        if ($this->input->method() !== 'post' || !$this->security->csrf_verify()) {
            flash_message('error', 'Invalid request.');
            redirect('admin/backups');
            return;
        }
        
        if (unlink($file_path)) {
            flash_message('success', 'Backup file deleted successfully.');
            log_activity('Backup deleted: ' . $filename);
        } else {
            flash_message('error', 'Could not delete the backup file.');
            log_activity('Failed to delete backup: ' . $filename, 'error');
        }
        
        redirect('admin/backups');
    }
    
    /**
     * Restore database from backup
     * 
     * @param string $filename Backup filename
     */
    public function restore($filename = '') {
        // This is a sensitive operation, add extra security
        // Only super-admin should be able to do this
        if (!isSuperAdmin()) {
            flash_message('error', 'Only super administrators can restore backups.');
            redirect('admin/backups');
            return;
        }
        
        // Validate filename to prevent directory traversal
        $filename = basename($filename);
        $file_path = $this->backupPath . $filename;
        
        if (!file_exists($file_path)) {
            flash_message('error', 'Backup file not found.');
            redirect('admin/backups');
            return;
        }
        
        // Add CSRF protection and require confirmation
        if ($this->input->method() !== 'post' || 
            !$this->security->csrf_verify() || 
            $this->input->post('confirm') != 'yes') {
            
            // Show confirmation page
            $data = array();
            $data['page_title'] = 'Confirm Database Restore';
            $data['filename'] = $filename;
            
            $this->load->view('admin/header', $data);
            $this->load->view('admin/backups/restore_confirm', $data);
            $this->load->view('admin/footer');
            return;
        }
        
        // User confirmed, proceed with restore
        try {
            // Log restore attempt
            log_activity('Database restore initiated: ' . $filename);
            
            // Backup current database before restore as a safeguard
            $backup_prefs = array(
                'format'        => 'txt',
                'filename'      => 'pre-restore-backup-' . date('Y-m-d-H-i-s') . '.sql',
                'add_drop'      => TRUE,
                'add_insert'    => TRUE,
                'newline'       => "\n",
                'foreign_key_checks' => FALSE,
            );
            
            $backup = $this->dbutil->backup($backup_prefs);
            write_file($this->backupPath . $backup_prefs['filename'], $backup);
            
            // Read SQL file
            $sql = file_get_contents($file_path);
            
            // Split into individual queries
            $queries = explode(';', $sql);
            
            // Begin transaction
            $this->db->trans_start();
            
            // Run each query
            foreach ($queries as $query) {
                $query = trim($query);
                if (!empty($query)) {
                    $this->db->query($query);
                }
            }
            
            // Complete transaction
            $this->db->trans_complete();
            
            if ($this->db->trans_status() === FALSE) {
                // Something went wrong
                flash_message('error', 'Database restore failed. A backup of the current state was created before the attempt.');
                log_activity('Database restore failed: ' . $filename, 'error');
            } else {
                // Success
                flash_message('success', 'Database restored successfully from backup.');
                log_activity('Database restored from: ' . $filename);
            }
        } catch (Exception $e) {
            flash_message('error', 'Restore failed: ' . $e->getMessage());
            log_activity('Restore failed: ' . $e->getMessage(), 'error');
        }
        
        redirect('admin/backups');
    }
    
    /**
     * Get list of backup files
     * 
     * @return array List of backup files with metadata
     */
    private function getBackupFiles() {
        $files = array();
        
        if (is_dir($this->backupPath)) {
            $dir_contents = scandir($this->backupPath);
            
            foreach ($dir_contents as $file) {
                if ($file === '.' || $file === '..' || $file === '.htaccess') continue;
                
                $file_path = $this->backupPath . $file;
                
                if (is_file($file_path) && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                    $files[] = array(
                        'name' => $file,
                        'size' => $this->formatFileSize(filesize($file_path)),
                        'date' => date('Y-m-d H:i:s', filemtime($file_path))
                    );
                }
            }
            
            // Sort by date (newest first)
            usort($files, function($a, $b) {
                return strtotime($b['date']) - strtotime($a['date']);
            });
        }
        
        return $files;
    }
    
    /**
     * Get total database size
     * 
     * @return int Size in bytes
     */
    private function getDatabaseSize() {
        $size = 0;
        
        // Get database name
        $dbName = $this->db->database;
        
        // Query to get size
        $query = $this->db->query("
            SELECT 
                SUM(data_length + index_length) AS size 
            FROM 
                information_schema.TABLES 
            WHERE 
                table_schema = '$dbName'
        ");
        
        if ($query->num_rows() > 0) {
            $row = $query->row();
            $size = $row->size;
        }
        
        return $size;
    }
    
    /**
     * Format file size to human-readable format
     * 
     * @param int $bytes Size in bytes
     * @return string Formatted size
     */
    private function formatFileSize($bytes) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}