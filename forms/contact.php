<?php
header('Content-Type: application/json'); // JSON output

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name    = htmlspecialchars(trim($_POST['name'] ?? ''));
    $email   = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $message = htmlspecialchars(trim($_POST['message'] ?? ''));

    if (!$name || !$email || !$message) {
        echo json_encode(["status" => "error", "message" => "All fields are required and email must be valid."]);
        exit;
    }

    $to      = "hpvconsortiumsocial@gmail.com";
    $subject = "New contact form message from $name";
    $fromEmail = "noreply@" . $_SERVER['SERVER_NAME'];

    $headers  = "From: $fromEmail\r\n";
    $headers .= "Reply-To: $email\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    $body  = "You have received a new message from the contact form:\n\n";
    $body .= "Name: $name\n";
    $body .= "Email: $email\n\n";
    $body .= "Message:\n$message\n";

    if (mail($to, $subject, $body, $headers)) {
        echo json_encode(["status" => "success", "message" => "Message sent successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to send message."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
?>
