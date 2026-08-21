<?php
require_once __DIR__ . '/assets/includes/session_boot.php';

$error = $_SESSION['error_registro'] ?? '';
$datos = $_SESSION['datos_registro'] ?? [];

// Se muestran una sola vez: al leerlos, se limpian de la sesión.
unset($_SESSION['error_registro'], $_SESSION['datos_registro']);
?>
<!DOCTYPE html>

<!--
     registro.php — Alta de nuevos pacientes.

     Esta vista solo dibuja el formulario; la lógica de guardado vive en
     assets/includes/guardar.php. Con el modelo usuarios+pacientes, un registro exitoso crea DOS filas relacionadas:

       1) Una fila en `usuarios`  -> nombre, apellido, correo, contraseña,
          teléfono, con id_rol = "paciente".
       2) Una fila en `pacientes` -> cédula, fecha de nacimiento y
          dirección, enlazada a la anterior mediante id_usuario.

     A diferencia de login.php, este formulario se envía a otro archivo
     (no a sí mismo) porque no necesita mostrar errores de validación en
     esta misma página todavía; guardar.php redirige de vuelta a
     login.php cuando termina.
-->

<html lang="es">

    <head>

        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>OdontoNet — Crear cuenta</title>
        <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">
        
        <!-------------- CSS -------------->
        <link rel="preconnect" href="https://fonts.googleapis.com"> <!-- Conexión temprana con los servidores de Google Fonts -->
        <link rel="stylesheet" href="assets/css/shared/variables.css"> <!-- CSS de variables -->
        <link rel="stylesheet" href="assets/css/shared/global.css"> <!-- CSS de estilos globales -->
        <link rel="stylesheet" href="assets/css/pages/registro.css"> <!-- CSS propio de la página de registro -->
        <link rel="stylesheet" href="assets/css/shared/layout.css"> <!-- CSS del layout (Navbar & Footer) -->
        <link rel="stylesheet" href="assets/css/shared/componentes.css"> <!-- CSS de los componentes (Botones y elementos) -->

    </head>

    <body>

        <div class="glow glow-a"></div>
        <div class="glow glow-b"></div>
        <div class="glow glow-c"></div>
        <div class="grid-overlay"></div>

        <main class="auth-page">

            <div class="login-card-glow">

                <div class="card login-card registro-card">

                    <div class="login-logo">
                        <a href="index.php" class="logo">
                            <img src="assets/images/logos/logo-auth.svg" alt="OdontoNet" class="logo-img">
                        </a>
                    </div>

                    <h1>Crear cuenta</h1>
                    <p class="login-subtitle">Regístrate para agendar tus citas y llevar tu historial dental en un solo lugar.</p>

                    <?php if ($error): ?>
                        <div class="form-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form action="assets/includes/guardar.php" method="post">

                        <div class="field">
                            <label for="nombre"><span class="dash-req">*</span> Nombre</label>
                            <input id="nombre" name="nombre" value="<?= htmlspecialchars($datos['nombre'] ?? '') ?>" placeholder="Nombre" required
                                maxlength="50"
                                pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+"
                                title="Solo se permiten letras y espacios.">
                        </div>

                        <div class="field-row">

                            <div class="field">
                                <label for="apellido"><span class="dash-req">*</span> Apellido</label>
                                <input id="apellido" name="apellido" value="<?= htmlspecialchars($datos['apellido'] ?? '') ?>" placeholder="Apellido" required
                                    maxlength="50"
                                    pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+"
                                    title="Solo se permiten letras y espacios.">
                            </div>
    
                            <div class="field">
                                <label for="fecha_nacimiento"><span class="dash-req">*</span> Fecha de nacimiento</label>
                                <input id="fecha_nacimiento" type="date" name="fecha_nacimiento" value="<?= htmlspecialchars($datos['fecha_nacimiento'] ?? '') ?>" required
                                    min="1876-01-01"
                                    max="<?= date('Y-m-d') ?>">
                            </div>

                        </div>

                        <div class="field-row">

                            <div class="field">
                                <label for="cedula"><span class="dash-req">*</span> Cédula</label>
                                <input id="cedula" name="cedula" value="<?= htmlspecialchars($datos['cedula'] ?? '') ?>" placeholder="123456789" required
                                    maxlength="9"
                                    inputmode="numeric"
                                    pattern="[0-9]{9}"
                                    title="La cédula debe tener exactamente 9 dígitos numéricos, sin guiones ni espacios.">
                            </div>
    
                            <div class="field">
                                <label for="telefono"><span class="dash-req">*</span> Teléfono</label>
                                <input id="telefono" name="telefono" value="<?= htmlspecialchars($datos['telefono'] ?? '') ?>" placeholder="88888888" required
                                    inputmode="numeric"
                                    pattern="[0-9]{8,15}"
                                    title="Solo números, sin espacios ni guiones.">
                            </div>

                        </div>
 
                        <div class="field">
                            <label for="correo"><span class="dash-req">*</span> Correo</label>
                            <input id="correo" type="email" name="correo" value="<?= htmlspecialchars($datos['correo'] ?? '') ?>" placeholder="tucorreo@ejemplo.com" required
                                maxlength="150"
                                title="Debe ser un correo electrónico válido">
                        </div>

                        <div class="field">
                            <label for="direccion">Dirección <span class="campo-opcional">(opcional)</span></label>
                            <input id="direccion" name="direccion" value="<?= htmlspecialchars($datos['direccion'] ?? '') ?>" placeholder="Dirección" maxlength="255">
                        </div>
 
                        <div class="field">

                            <label for="clave"><span class="dash-req">*</span> Contraseña</label>
                            <input 
                                id="clave" 
                                type="password" 
                                name="clave" 
                                placeholder="••••••••"
                                pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}"
                                title="La contraseña debe tener al menos 8 caracteres, incluir una mayúscula, una minúscula, un número y un carácter especial."
                                required>

                        </div>
 
                        <button class="btn-primary" type="submit">Crear cuenta</button>

                    </form>

                    <div class="login-footer">
                        <span class="paragraph">¿Ya tienes cuenta?</span>
                        <a href="login.php" class="link">Iniciar sesión</a>
                    </div>

                </div>

            </div>

        </main>

    </body>

</html>