<?php

// =====================================================================
//  PLANTILLA: copia este archivo como config.local.php
//  Solo cambia los valores marcados con "PON AQUI". No compartas este archivo.
//  En InfinityFree súbelo a htdocs/ junto a config.php.
// =====================================================================
return [

    // ---------- BASE DE DATOS ----------
    // En tu computadora (XAMPP): 127.0.0.1 / root / (vacío) / apac_carlos
    // En InfinityFree: panel > MySQL Databases (host sqlXXX.infinityfree.com,
    // usuario if0_XXXXXXXX, nombre if0_XXXXXXXX_apac, contraseña = la de tu cuenta de hosting)
    'db_host' => '127.0.0.1',        // PON AQUI
    'db_user' => 'root',             // PON AQUI
    'db_pass' => '',                 // PON AQUI
    'db_name' => 'apac_carlos',   // PON AQUI

    // ---------- CORREO CON GMAIL ----------
    // 1. Activa la verificación en dos pasos en tu cuenta de Google.
    // 2. Ve a https://myaccount.google.com/apppasswords y crea una contraseña de aplicación.
    // 3. Pega aquí tu Gmail y esa contraseña de 16 letras (NO tu contraseña normal).
    'smtp_usuario' => 'tucorreo@gmail.com',     // PON AQUI tu Gmail
    'smtp_clave'   => 'xxxx xxxx xxxx xxxx',    // PON AQUI la contraseña de aplicación

    // Nombre que verán las familias como remitente
    'correo_nombre' => 'APAC Fresnillo',

    // ---------- NO CAMBIAR ----------
    'smtp_host'   => 'smtp.gmail.com',
    'smtp_puerto' => 587,                 // InfinityFree solo permite el 587
    'correo_remitente' => '',             // vacío = se usa tu mismo Gmail
    'brevo_api_key' => '',                // vacío = no se usa Brevo

    // Ponlo en true solo si el correo falla, para ver el motivo exacto en la página.
    'debug' => false,
];
