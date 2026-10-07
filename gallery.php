<?php
$category = $_GET['category'];
$dir = "images/" . basename($category);
$images = glob($dir . "/*.webp");
if (!$images) {
    $images = glob($dir . "/*.{jpg,jpeg,png}", GLOB_BRACE);
}

// If requesting a thumbnail
if (isset($_GET['thumb'])) {
    echo isset($images[0]) ? $images[0] : "placeholder.jpg"; // fallback image
    exit;
}

// Otherwise return JSON array of image paths
echo json_encode($images);
?>

