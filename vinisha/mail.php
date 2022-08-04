<?php

$name = $_POST['name'];
$email = $_POST['email'];
$subject = $_POST['subject'];

$mailheader = "From:".$name."<".$email.">\r\n";

$recipient = "3pbrains@gmail.com";

mail($recipient, $subject, $message, $mailheader) or die("Error!");

//redirect
header("Location: https://tawk.to/vinisha3pb");

?>



