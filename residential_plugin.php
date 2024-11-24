<?php
/*
Plugin Name: Residencial Plugin
Description: Plugin para gestionar datos de clientes, residentes y recibos de pagos del residencial.
Version: 1.2
Author: Víctor Méndez
*/

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}


//Config del plugin de Brevo (para e-mails)
require_once 'brevo_config.php';



// Crear tablas en la base de datos al activar el plugin
function residencial_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Tabla para clientes
    $sql_clients = "CREATE TABLE {$wpdb->prefix}residencial_clients (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        ci  varchar(50) NOT NULL,
        phone varchar(50) NOT NULL,
        email varchar(100) NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // Tabla para residentes
    $sql_residents = "CREATE TABLE {$wpdb->prefix}residencial_residents (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        ci varchar(50) NOT NULL,
        birth_date date NOT NULL,
        entry_date date NOT NULL,
        family_name varchar(255) NULL,
        family_phone text NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // Tabla para recibos
    $sql_receipts = "CREATE TABLE {$wpdb->prefix}residencial_receipts (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        client_id mediumint(9) NOT NULL,
        resident_id mediumint(9) NULL,
        date_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        receipt_code varchar(64) NOT NULL,
        amount decimal(10,2) NOT NULL,
        concept varchar(1000) NOT NULL,
        FOREIGN KEY (client_id) REFERENCES {$wpdb->prefix}residencial_clients(id),
        FOREIGN KEY (resident_id) REFERENCES {$wpdb->prefix}residencial_residents(id),
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_clients);
    dbDelta($sql_residents);
    dbDelta($sql_receipts);
}

register_activation_hook(__FILE__, 'residencial_create_tables');

// Verificar que el usuario esté autenticado
function is_user_logged_in_rest() {
    return is_user_logged_in();
}




//Precond: Enviar un array asociativo con cliente_nombre, ci_cliente, residente_nombre, ci_residente, monto, concepto, fecha y recibo_id
function enviar_correo_recibo($datos) {
    // Endpoint de Brevo
    $url = 'https://api.brevo.com/v3/smtp/email';

    // Contenido del email en formato HTML enriquecido
    $htmlContent = "
    <h1 style='color: #444;'>Recibo - Hogar de Ancianos Blanca Rubio de Rubio</h1>
    <p>Estimado/a <strong>{$datos['cliente_nombre']}</strong>,</p>
    <p>Este correo confirma la recepción de su pago. Por favor, guarde este mensaje como recibo oficial.</p>
    <hr>
    <p><strong>Cliente:</strong> {$datos['cliente_nombre']}</p>
    <p><strong>ID Cliente:</strong> {$datos['ci_cliente']}</p>
    <p><strong>Residente:</strong> {$datos['residente_nombre']}</p>
    <p><strong>ID Residente:</strong> {$datos['ci_residente']}</p>
    <p><strong>Monto:</strong> {$datos['monto']} UYU </p>
    <p><strong>Concepto:</strong> {$datos['concepto']}</p>
    <p><strong>Fecha/Hora:</strong> {$datos['fecha']}</p>
    <p><strong>ID Recibo:</strong> {$datos['recibo_id']}</p>
    <hr>
    <p>Este correo y el número de comprobante sirven como recibo oficial y pueden ser impresos para cualquier reclamo.</p>
    <p>Atentamente,</p>
    <p>Hogar de Ancianos Blanca Rubio de Rubio</p>
    ";

    // Configurar el payload para la API
    $emailData = [
        'sender' => [
            'name' => 'Hogar de Ancianos',
            'email' => 'vicanmendez@gmail.com' // Cambia esto por tu email configurado en Brevo
        ],
        'to' => [
            [
                'email' => $datos['email_cliente'],
                'name' => $datos['cliente_nombre']
            ]
        ],
        'subject' => 'Recibo - Hogar de Ancianos Blanca Rubio de Rubio',
        'htmlContent' => $htmlContent,
    ];

    // Configurar el encabezado con la API Key de Brevo
    $headers = [
        'api-key: ' . BREVO_API_KEY,
        'Content-Type: application/json'
    ];

    // Enviar la solicitud a la API de Brevo
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Verificar si el correo se envió correctamente
    if ($httpCode == 201) {
        return true; // Enviado correctamente
    } else {
        error_log('Error al enviar el correo: ' . $response);
        return false; // Fallo en el envío
    }
}


add_action('save_post_recibo', function($post_id) {
    // Obtener los datos del recibo
    $datos = [
        'cliente_nombre' => get_post_meta($post_id, 'cliente_nombre', true),
        'ci_cliente' => get_post_meta($post_id, 'ci_cliente', true),
        'residente_nombre' => get_post_meta($post_id, 'residente_nombre', true),
        'ci_residente' => get_post_meta($post_id, 'ci_residente', true),
        'monto' => get_post_meta($post_id, 'monto', true),
        'concepto' => get_post_meta($post_id, 'concepto', true),
        'fecha' => get_post_meta($post_id, 'fecha', true),
        'recibo_id' => get_post_meta($post_id, 'recibo_id', true),
        'email_cliente' => get_post_meta($post_id, 'email_cliente', true),
    ];

    // Enviar el correo
    if (!enviar_correo_recibo($datos)) {
        error_log('No se pudo enviar el recibo por correo.');
    }
});





// API REST para gestionar los clientes
add_action('rest_api_init', function () {
    register_rest_route('residencial/v1', '/clients', [
        'methods' => 'GET',
        'callback' => 'get_clients',
        'permission_callback' => 'is_user_logged_in_rest'
    ]);

    register_rest_route('residencial/v1', '/clients', [
        'methods' => 'POST',
        'callback' => 'create_client',
        'permission_callback' => 'is_user_logged_in_rest'
    ]);

    register_rest_route('residencial/v1', '/clients/(?P<id>\d+)', [
        'methods' => 'PUT',
        'callback' => 'update_client',
        'permission_callback' => 'is_user_logged_in_rest'
    ]);

    register_rest_route('residencial/v1', '/clients/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'delete_client',
        'permission_callback' => 'is_user_logged_in_rest'
    ]);
});

// API REST para gestionar los residentes
add_action('rest_api_init', function () {
    register_rest_route('residencial/v1', '/residents', [
        'methods' => 'GET',
        'callback' => 'get_residents',
        'permission_callback' => 'is_user_logged_in_rest'
    ]);

    register_rest_route('residencial/v1', '/residents', [
        'methods' => 'POST',
        'callback' => 'create_resident',
        'permission_callback' => 'is_user_logged_in_rest'
    ]);

    register_rest_route('residencial/v1', '/residents/(?P<id>\d+)', [
        'methods' => 'PUT',
        'callback' => 'update_resident',
        'permission_callback' => 'is_user_logged_in_rest'
    ]);

    register_rest_route('residencial/v1', '/residents/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'delete_resident',
        'permission_callback' => 'is_user_logged_in_rest'
    ]);
});

// API REST para gestionar los recibos
add_action('rest_api_init', function () {
    register_rest_route('residencial/v1', '/receipts', [
        'methods' => 'GET',
        'callback' => 'get_receipts',
        'permission_callback' => 'is_user_logged_in_rest'
    ]);

    register_rest_route('residencial/v1', '/receipts', [
        'methods' => 'POST',
        'callback' => 'create_receipt',
        'permission_callback' => 'is_user_logged_in_rest'
    ]);

    register_rest_route('residencial/v1', '/receipts/(?P<id>\d+)', [
        'methods' => 'PUT',
        'callback' => 'update_receipt',
        'permission_callback' => 'is_user_logged_in_rest'
    ]);

    register_rest_route('residencial/v1', '/receipts/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'delete_receipt',
        'permission_callback' => 'is_user_logged_in_rest'
    ]);
});

// Funciones para gestionar clientes
function get_clients() {
    global $wpdb;
    $clients = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}residencial_clients");
    return rest_ensure_response($clients);
}

function create_client(WP_REST_Request $request) {
    global $wpdb;

    $name = sanitize_text_field($request->get_param('name'));
    $ci = sanitize_text_field($request->get_param('ci'));
    $phone = sanitize_text_field($request->get_param('phone'));
    $email = sanitize_email($request->get_param('email'));

    $wpdb->insert(
        "{$wpdb->prefix}residencial_clients",
        [
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'ci' => $ci
        ]
    );

    return rest_ensure_response('Client created successfully');
}

function update_client(WP_REST_Request $request) {
    global $wpdb;
    $id = (int) $request->get_param('id');

    $name = sanitize_text_field($request->get_param('name'));
    $ci = sanitize_text_field($request->get_param('ci'));
    $phone = sanitize_text_field($request->get_param('phone'));
    $email = sanitize_email($request->get_param('email'));

    $wpdb->update(
        "{$wpdb->prefix}residencial_clients",
        [
            'name' => $name,
            'ci' => $ci,
            'phone' => $phone,
            'email' => $email
        ],
        ['id' => $id]
    );

    return rest_ensure_response('Client updated successfully');
}

function delete_client(WP_REST_Request $request) {
    global $wpdb;
    $id = (int) $request->get_param('id');
    
    $wpdb->delete("{$wpdb->prefix}residencial_clients", ['id' => $id]);

    return rest_ensure_response('Client deleted successfully');
}

// Funciones para gestionar residentes
function get_residents() {
    global $wpdb;
    $residents = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}residencial_residents");
    return rest_ensure_response($residents);
}

function create_resident(WP_REST_Request $request) {
    global $wpdb;

    $name = sanitize_text_field($request->get_param('name'));
    $ci = sanitize_text_field($request->get_param('ci'));
    $birth_date = sanitize_text_field($request->get_param('birth_date'));
    $entry_date = sanitize_text_field($request->get_param('entry_date'));
    $family_name = sanitize_text_field($request->get_param('family_name'));
    $family_phone = sanitize_text_field($request->get_param('family_phone'));

    $wpdb->insert(
        "{$wpdb->prefix}residencial_residents",
        [
            'name' => $name,
            'ci' => $ci,
            'birth_date' => $birth_date,
            'entry_date' => $entry_date,
            'family_name' => $family_name,
            'family_phone' => $family_phone
        ]
    );

    return rest_ensure_response('Resident created successfully');
}

function update_resident(WP_REST_Request $request) {
    global $wpdb;
    $id = (int) $request->get_param('id');

    $name = sanitize_text_field($request->get_param('name'));
    $ci = sanitize_text_field($request->get_param('ci'));
    $birth_date = sanitize_text_field($request->get_param('birth_date'));
    $entry_date = sanitize_text_field($request->get_param('entry_date'));
    $family_name = sanitize_text_field($request->get_param('family_name'));
    $family_phone = sanitize_text_field($request->get_param('family_phone'));

    $wpdb->update(
        "{$wpdb->prefix}residencial_residents",
        [
            'name' => $name,
            'ci' => $ci,
            'birth_date' => $birth_date,
            'entry_date' => $entry_date,
            'family_name' => $family_name,
            'family_phone' => $family_phone
        ],
        ['id' => $id]
    );

    return rest_ensure_response('Resident updated successfully');
}

function delete_resident(WP_REST_Request $request) {
    global $wpdb;
    $id = (int) $request->get_param('id');
    
    $wpdb->delete("{$wpdb->prefix}residencial_residents", ['id' => $id]);

    return rest_ensure_response('Resident deleted successfully');
}

// Funciones para gestionar recibos
function get_receipts() {
    global $wpdb;
    $receipts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}residencial_receipts");
    return rest_ensure_response($receipts);
}

function create_receipt(WP_REST_Request $request) {
    global $wpdb;
    $client_id = (int) $request->get_param('client_id');
    $resident_id = $request->get_param('resident_id') ? (int) $request->get_param('resident_id') : null;
    $amount = (float) $request->get_param('amount');
    $concept = sanitize_text_field($request->get_param('concept'));
    $random_number = rand(1000000000, 9999999999); // Número aleatorio de 10 cifras
    $date_time = date('Y-m-d H:i:s');
    
    // Generar el código de recibo (SHA256)
    $receipt_code = hash('sha256', $client_id . $random_number . $date_time);

    // Insertar el recibo en la base de datos
    $result = $wpdb->insert(
        "{$wpdb->prefix}residencial_receipts",
        [
            'client_id' => $client_id,
            'resident_id' => $resident_id,
            'date_time' => $date_time,
            'receipt_code' => $receipt_code,
            'amount' => $amount,
            'concept' => $concept
        ]
    );

    if ($result) {
        // Obtener datos adicionales del cliente
        $client_data = $wpdb->get_row(
            $wpdb->prepare("SELECT name, email FROM {$wpdb->prefix}residencial_clients WHERE id = %d", $client_id),
            ARRAY_A
        );

        if ($client_data) {
            // Datos para enviar el correo
            $datos = [
                'cliente_nombre' => $client_data['name'],
                'ci_cliente' => $client_id,
                'residente_nombre' => $resident_id ? $wpdb->get_var(
                    $wpdb->prepare("SELECT name FROM {$wpdb->prefix}residencial_residents WHERE id = %d", $resident_id)
                ) : 'N/A',
                'ci_residente' => $resident_id ?? 'N/A',
                'monto' => $amount,
                'concepto' => $concept,
                'fecha' => $date_time,
                'recibo_id' => $receipt_code,
                'email_cliente' => $client_data['email'],
            ];

            // Enviar el correo
            if (!enviar_correo_recibo($datos)) {
                error_log("No se pudo enviar el correo para el recibo con ID: $receipt_code");
            }
        }
    }

    return rest_ensure_response('Receipt created successfully');
}


function update_receipt(WP_REST_Request $request) {
    global $wpdb;
    $id = (int) $request->get_param('id');

    $client_id = (int) $request->get_param('client_id');
    $resident_id = $request->get_param('resident_id') ? (int) $request->get_param('resident_id') : null;
    $amount = (float) $request->get_param('amount');
    $concept = sanitize_text_field($request->get_param('concept'));

    $wpdb->update(
        "{$wpdb->prefix}residencial_receipts",
        [
            'client_id' => $client_id,
            'resident_id' => $resident_id,
            'amount' => $amount,
            'concept' => $concept
        ],
        ['id' => $id]
    );

    return rest_ensure_response('Receipt updated successfully');
}

function delete_receipt(WP_REST_Request $request) {
    global $wpdb;
    $id = (int) $request->get_param('id');
    
    $wpdb->delete("{$wpdb->prefix}residencial_receipts", ['id' => $id]);

    return rest_ensure_response('Receipt deleted successfully');
}
?>
