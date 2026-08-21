<?php
/**
 * paginacion_helper.php — Paginación "con ventana" para tablas grandes.
 *
 * Sin esto, la paginación de las páginas de dashboard (inventario.php,
 * usuarios.php, control_citas.php, bitacora.php) generaba un botón por
 * cada página existente sin ningún límite: con miles de registros serían
 * miles de botones en una sola fila.
 *
 * obtener_paginas_visibles() devuelve solo la página 1, la última, la
 * página actual y unas pocas vecinas, marcando los huecos con '...'.
 * Ejemplo con la página 6 de 42: [1, '...', 4, 5, 6, 7, 8, '...', 42].
 *
 * Uso:
 *   require_once './assets/includes/helpers/paginacion_helper.php';
 *   foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item) {
 *       if ($item === '...') { // mostrar puntos suspensivos
 *       } else { // $item es un número de página
 *       }
 *   }
 */

function obtener_paginas_visibles(int $pagina_actual, int $total_paginas, int $vecinas = 2): array
{
    // Si solo hay una página (o ninguna), no hace falta calcular nada raro.
    if ($total_paginas <= 1) {
        return [1];
    }

    // Siempre mostramos la primera y la última página.
    $paginas_mostrar = [1, $total_paginas];
    // Y también las páginas "vecinas" a la actual (por defecto, 2 antes y 2 después).
    for ($i = $pagina_actual - $vecinas; $i <= $pagina_actual + $vecinas; $i++) {
        if ($i >= 1 && $i <= $total_paginas) {
            $paginas_mostrar[] = $i;
        }
    }

    // Quitamos números repetidos (por ejemplo, si la página 1 ya era vecina) y las ordenamos de menor a mayor.
    $paginas_mostrar = array_unique($paginas_mostrar);
    sort($paginas_mostrar);

    // Recorremos la lista final y metemos '...' donde haya un salto (un hueco) entre dos números.
    $resultado = [];
    $anterior = null;
    foreach ($paginas_mostrar as $pagina_num) {
        if ($anterior !== null && $pagina_num - $anterior > 1) {
            $resultado[] = '...';
        }
        $resultado[] = $pagina_num;
        $anterior = $pagina_num;
    }

    return $resultado;
}
