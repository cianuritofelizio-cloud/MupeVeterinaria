<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Veterinaria MUPE</title>
    <style>
        :root {
            --principal1: #E9A0A0; /* Color de fondo principal */
            --principal3: #030303; /* Color de texto principal */
            --secundario3: #E9A0A0; /* Color de hover */
            --border-color: #E50F53; /* Color del borde superior */
        }

        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: Arial, sans-serif;
        }

        .footer {
            background-color: var(--principal1);
            color: var(--principal3);
            text-align: center;
            padding: 2rem 1rem;
            border-top: 3px solid var(--border-color);
            width: 100%;
            margin-top: auto; /* Empuja el footer al final de la página */
            font-family: Arial, sans-serif;
        }

        .footer p {
            margin: 0 0 1rem 0;
            font-weight: bold;
            font-size: 1rem;
        }

        .redes {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap; /* Permite que los enlaces se envuelvan en pantallas pequeñas */
            gap: 1rem; /* Espacio entre enlaces */
        }

        .redes a {
            color: var(--principal3);
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s ease;
            padding: 0.5rem;
            border-radius: 4px;
        }

        .redes a:hover,
        .redes a:focus {
            color: var(--secundario3);
            background-color: rgba(255, 255, 255, 0.1); /* Ligero fondo para mejor accesibilidad */
        }

        /* Responsividad: Ajustes para pantallas pequeñas */
        @media (max-width: 768px) {
            .footer {
                padding: 1.5rem 0.5rem;
            }
            .footer p {
                font-size: 0.9rem;
            }
            .redes {
                gap: 0.5rem;
            }
            .redes a {
                font-size: 0.9rem;
                padding: 0.4rem;
            }
        }
    </style>
</head>
<body>
    <!-- Aquí va el contenido principal de tu página -->
    <main>
        <!-- Contenido de la página -->
    </main>

    <footer class="footer">
        <p>Veterinaria MUPE © 2025</p>
        <nav class="redes" aria-label="Redes sociales">
            <a href="#" aria-label="Facebook">Facebook</a>
            <a href="#" aria-label="Instagram">Instagram</a>
            <a href="#" aria-label="WhatsApp">WhatsApp</a>
        </nav>
    </footer>
</body>
</html>