<?php
// footer.php — Footer compartido de OdontoNet, incluido en todas las páginas.
// Se incluye con: require_once 'assets/includes/partials/footer.php';
?>
<footer id="contacto">
    <div class="wrap">
        <div class="footer-grid">

            <div>
                <div class="logo" style="margin-bottom:16px;">
                    <img src="assets/images/logos/logo-navbar.svg" alt="OdontoNet" class="logo-img">
                </div>
                <p style="color:var(--muted); font-size:0.88rem; max-width:280px; line-height:1.6;">
                    Tu clínica dental digital: agenda tus citas, revisa tu historial y cuida tu sonrisa desde un solo lugar.
                </p>
            </div>

            <div>
                <h4>Explorar</h4>
                <ul>
                    <li><a href="index.php#servicios">Servicios</a></li>
                    <li><a href="index.php#beneficios">Beneficios</a></li>
                    <li><a href="index.php#como-funciona">Cómo funciona</a></li>
                </ul>
            </div>

            <div>
                <h4>Clínica</h4>
                <ul>
                    <li><a href="#">Sobre nosotros</a></li>
                    <li><a href="#">Equipo</a></li>
                    <li><a href="#">Preguntas frecuentes</a></li>
                </ul>
            </div>

            <div>
                <h4>Contacto</h4>
                <ul>
                    <li><a href="mailto:contacto@odontonet.com">contacto@odontonet.com</a></li>
                    <li><a href="tel:+50600000000">+506 0000-0000</a></li>
                    <li><a href="#">San José, Costa Rica</a></li>
                </ul>
            </div>

        </div>

        <div class="footer-bottom">
            <!-- date('Y') escribe el año actual automáticamente, así no hay que actualizarlo a mano cada año. -->
            <span>© <?= date('Y') ?> OdontoNet. Todos los derechos reservados.</span>
            <span>Proyecto académico — Programación IV, UTC</span>
        </div>
    </div>
</footer>