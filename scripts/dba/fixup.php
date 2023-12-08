<?php

// Can be used as a template for future DB fixes.

// Database connection
$db = mysqli_connect("127.0.0.1", "framework2", "framework2", "framework2");

// Fetch all transportID and class from the transports table
$result = $db->query("SELECT something FROM something");
while ($row = $result->fetch_assoc()) {
    // Do something
}
