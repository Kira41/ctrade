<?php
// File path
$filePath = __DIR__ . '/files/AnyDesk.exe'; // Change path & file name

if (file_exists($filePath)) {
    // Send download headers
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Pragma: public');
    header('Cache-Control: must-revalidate');

    flush(); // Clean any previous output
    readfile($filePath); // Output file
    exit;
} else {
    echo "File not found.";
}
