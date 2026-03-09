<?php
    $host = "LAPTOP-R04OAJ46\SQLEXPRESS";
    $connection = [
        "Database" => "FAB_ULOUS",
        "Uid" => "",
        "PWD" => ""
    ];
    $conn = sqlsrv_connect($host, $connection);

    if ($conn === false) {
        die(print_r(sqlsrv_errors(),true));
    }

    $firstName = $_POST['firstName'];
    $lastName = $_POST['lastName'];
    $username = $_POST['username'];
    $email = $_POST['email'];
    $password = $_POST['password'];

    $sql = "INSERT INTO ACCOUNTS(FIRST_NAME,LAST_NAME,USERNAME,EMAIL,PASSWORD)
    VALUES ('$firstName','$lastName', '$username','$email', '$password')";

    $result = sqlsrv_query($conn,$sql);

    if ($result){
        header("Location: html/Post.html");
        exit();
    }
    else{
        die(print_r(sqlsrv_errors(),true));
    }
?>