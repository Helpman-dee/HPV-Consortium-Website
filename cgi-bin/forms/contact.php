

<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name    = $_POST['name'] ?? '';
    $email   = $_POST['email'] ?? '';
    $message = $_POST['message'] ?? '';

    $to = "hpvconsortiumsocial@gmail.com"; // Change to your actual email
    $subject = "New contact form message from $name";
    $headers = "From: $email\r\nReply-To: $email";

    if (mail($to, $subject, $message, $headers)) {
        echo "Message sent successfully.";
    } else {
        echo "Failed to send message.";
    }
}
?>
