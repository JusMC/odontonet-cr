<?php
require_once __DIR__ . '/assets/includes/session_boot.php';

// index.php — Página de presentación pública (landing page) de OdontoNet.

// Esta página NO requiere sesión iniciada: es lo primero que ve cualquier visitante (paciente potencial) antes de registrarse o iniciar sesión.

    $year = date("Y"); // Año actual, usado más abajo en el footer para el aviso de copyright (evita tener que actualizar el año a mano cada 12 meses).
?>

<!DOCTYPE html>
<html lang="es">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>OdontoNet — Tu clínica dental digital</title>
        <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

        <!------------ CSS ------------>
    <!------------ CSS ------------>
<link rel="preconnect" href="https://fonts.googleapis.com">

<!-- Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- CSS propio de OdontoNet -->
<link rel="stylesheet" href="assets/css/shared/variables.css">
<link rel="stylesheet" href="assets/css/shared/global.css">
<link rel="stylesheet" href="assets/css/shared/home.css">
<link rel="stylesheet" href="assets/css/shared/layout.css">
<link rel="stylesheet" href="assets/css/shared/componentes.css">
        
    </head>

    <body id="inicio">

        <!-------------- Elementos decorativos de fondo -------------->
        <div class="glow glow-a"></div>
        <div class="glow glow-b"></div>
        <div class="glow glow-c"></div>
        <div class="grid-overlay"></div>

        <!-- NAVBAR -->
        <?php require_once 'assets/includes/partials/navbar.php'; ?>

        <main>

            <!-- HERO -->
            <section class="hero wrap">

                <div>
                    
                    <span class="eyebrow">Clínica dental digital</span>
                    <h1>Tu sonrisa, <span class="grad">cuidada de principio a fin</span></h1>

                    <p class="lead">
                        En OdontoNet agendas tu cita, revisas tu historial y compras tus productos de cuidado dental
                        desde un solo lugar. Sin llamadas, sin filas, sin papeleo.
                    </p>

                    <!-- Botones principales -->
                    <div class="hero-ctas">
                        <a href="#contacto" class="btn-primary">Agendar mi cita</a>
                        <a href="#como-funciona" class="btn-ghost">Ver cómo funciona</a>
                    </div>

                    <!-- Estadísticas rápidas -->
                    <div class="hero-stats">

                        <div>
                            <strong>+2,400</strong>
                            <span>Citas atendidas al mes</span>
                        </div>

                        <div>
                            <strong>98%</strong>
                            <span>Pacientes que confirman a tiempo</span>
                        </div>

                        <div>
                            <strong>&lt;5s</strong>
                            <span>Para agendar tu próxima cita</span>
                        </div>

                    </div>

                </div>

                <!-- Panel visual decorativo -->
                <div class="signature">

                    <span class="signature-tag"><span class="dot"></span> monitor_paciente.live</span>

                    <svg class="scanline" viewBox="0 0 520 200" xmlns="http://www.w3.org/2000/svg">

                        <defs>
                            <linearGradient id="lineGrad" x1="0" y1="0" x2="1" y2="0">
                                <stop offset="0%" stop-color="#00f0ff"/>
                                <stop offset="55%" stop-color="#7b61ff"/>
                                <stop offset="100%" stop-color="#ff2e92"/>
                            </linearGradient>
                        </defs>

                        <path class="scan-path" d="M0,100 L60,100 L80,40 L100,160 L120,100 L170,100
                        C 210,100 220,150 260,150
                        C 300,150 300,60 340,60
                        C 380,60 380,150 420,150
                        C 450,150 460,100 520,100" />

                    </svg>

                    <div class="signature-caption">
                        <div>
                            <strong>Tu salud dental, siempre al día</strong>
                            <p>Cada cita, tratamiento y control queda registrado para que tu odontólogo te conozca desde el primer minuto.</p>
                        </div>
                    </div>

                </div>

            </section>

            <!-------------- SERVICIOS -------------->
            <section id="servicios" class="wrap">

                <div class="sec-head">

                    <span class="eyebrow">Servicios</span>
                    <h2>Cuidado dental completo, para toda la familia</h2>
                    <p>En OdontoNet encuentras desde tu chequeo de rutina hasta tratamientos especializados, con el mismo equipo dándole seguimiento a tu sonrisa.</p>

                </div>

                <div class="services-grid">

                    <div class="service-card">
                        <div class="service-icon">
                            <img src="./assets/images/icons/tooth-2.png" alt="Chequeo general">
                        </div>
                        <h3>Chequeo y limpieza</h3>
                        <p>Valoración general, limpieza profesional y detección temprana de caries o problemas de encías.</p>
                    </div>

                    <div class="service-card">
                        <div class="service-icon">
                            <img src="./assets/images/icons/tooth.png" alt="Blanqueamiento">
                        </div>
                        <h3>Blanqueamiento dental</h3>
                        <p>Tratamientos estéticos seguros para recuperar el brillo natural de tu sonrisa.</p>
                    </div>

                    <div class="service-card">
                        <div class="service-icon">
                            <img src="./assets/images/icons/dental-checkup.png" alt="Ortodoncia">
                        </div>
                        <h3>Ortodoncia</h3>
                        <p>Brackets y alineadores con planes de seguimiento personalizados según tu tratamiento.</p>
                    </div>

                    <div class="service-card">
                        <div class="service-icon">
                            <img src="./assets/images/icons/teeth.png" alt="Endodoncia">
                        </div>
                        <h3>Endodoncia</h3>
                        <p>Tratamiento de conducto realizado con tecnología moderna y mínima molestia.</p>
                    </div>

                    <div class="service-card">
                        <div class="service-icon">
                            <img src="./assets/images/icons/dental-care.png" alt="Urgencias">
                        </div>
                        <h3>Urgencias dentales</h3>
                        <p>Atención prioritaria para dolor, fracturas o molestias que no pueden esperar.</p>
                    </div>

                    <div class="service-card">
                        <div class="service-icon">
                            <img src="./assets/images/icons/tooth-cleaning-kit.png" alt="Tienda">
                        </div>
                        <h3>Productos de cuidado</h3>
                        <p>Cepillos, pastas y enjuagues recomendados por tu odontólogo, listos para comprar en línea.</p>
                    </div>

                </div>

            </section>

            <!-------------- BENEFICIOS -------------->
            <section id="beneficios" class="wrap">

                <div class="benefits">

                    <div>

                        <span class="eyebrow">Por qué OdontoNet</span>
                        <h2 style="margin:16px 0 30px; font-size:2rem;">Pensado para que cuidarte sea simple</h2>

                        <div class="benefit-row">
                            <div class="benefit-icon">
                                <img src="./assets/images/icons/speed.png" alt="Rapidez">
                            </div>
                            <div>
                                <h3>Agenda en segundos</h3>
                                <p>Elige día, hora y odontólogo disponible sin necesidad de llamar a la clínica.</p>
                            </div>
                        </div>

                        <div class="benefit-row">
                            <div class="benefit-icon">
                                <img src="./assets/images/icons/shield.png" alt="Privacidad">
                            </div>
                            <div>
                                <h3>Tu información, protegida</h3>
                                <p>Solo tú y tu equipo de confianza en la clínica pueden ver tu historial.</p>
                            </div>
                        </div>

                        <div class="benefit-row">
                            <div class="benefit-icon">
                                <img src="./assets/images/icons/synchronize.png" alt="Seguimiento">
                            </div>
                            <div>
                                <h3>Seguimiento continuo</h3>
                                <p>Tus citas, tratamientos y compras quedan en un solo historial, sin repetir tu información cada vez.</p>
                            </div>
                        </div>

                    </div>

                    <!-- Panel de KPIs -->
                    <div class="benefits-panel">
                        <span class="eyebrow">Lo que te llevas</span>
                        <h3 style="margin-top:14px; font-size:1.3rem;">Cuidado dental sin complicaciones</h3>

                        <div class="kpi-grid">

                            <div class="kpi">
                                <strong>24/7</strong>
                                <span>Agenda tu cita cuando quieras</span>
                            </div>

                            <div class="kpi">
                                <strong>+30%</strong>
                                <span>Pacientes que llegan a tiempo</span>
                            </div>

                            <div class="kpi">
                                <strong>1 historial</strong>
                                <span>Con todos tus tratamientos</span>
                            </div>

                            <div class="kpi">
                                <strong>0 filas</strong>
                                <span>Para comprar tus productos</span>
                            </div>

                        </div>
                    </div>

                </div>

            </section>

            <!-------------- CÓMO FUNCIONA -------------->
            <section id="como-funciona" class="wrap">

                <div class="sec-head">
                    <span class="eyebrow">Cómo funciona</span>
                    <h2>De agendar tu cita a salir con tu sonrisa lista</h2>
                    <p>Así de simple es tu experiencia como paciente en OdontoNet, de principio a fin.</p>
                </div>

                <div class="steps">

                    <div class="step">
                        <span class="num">01</span>
                        <div class="step-icon">
                            <img src="./assets/images/icons/calendar.png" alt="Agenda">
                        </div>
                        <h3>Agenda tu cita</h3>
                        <p>Elige el servicio, la fecha y el odontólogo que prefieras, en el momento que quieras.</p>
                    </div>

                    <div class="step">
                        <span class="num">02</span>
                        <div class="step-icon">
                            <img src="./assets/images/icons/check-mark.png" alt="Confirmación">
                        </div>
                        <h3>Recibe tu confirmación</h3>
                        <p>Te llega un recordatorio para que no se te pase la fecha de tu consulta.</p>
                    </div>

                    <div class="step">
                        <span class="num">03</span>
                        <div class="step-icon">
                            <img src="./assets/images/icons/medical-doctor.png" alt="Atención">
                        </div>
                        <h3>Recibe tu tratamiento</h3>
                        <p>Tu odontólogo revisa tu historial y te atiende con toda la información a mano.</p>
                    </div>

                    <div class="step">
                        <span class="num">04</span>
                        <div class="step-icon">
                            <img src="./assets/images/icons/receipt-approved.png" alt="Seguimiento">
                        </div>
                        <h3>Da seguimiento</h3>
                        <p>Consulta tu historial, tus próximos controles y tus compras desde tu perfil.</p>
                    </div>

                </div>

            </section>

            <!-------------- CTA FINAL -------------->
            <section class="wrap">

                <div class="cta-section">

                    <div>
                        <span class="eyebrow">Empecemos</span>
                        <h2>¿Lista tu próxima cita?</h2>
                        <p>Crea tu cuenta o inicia sesión para agendar en minutos y llevar el control de tu salud dental.</p>
                    </div>

                    <a href="login.php?redirect=citas.php" class="btn-primary">Agendar mi cita</a>

                </div>

            </section>

        </main>

        <!-------------- FOOTER -------------->
        <?php require_once 'assets/includes/partials/footer.php'; ?>

    </body>

</html>