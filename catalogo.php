<?php
declare(strict_types=1);

const CHINA_NAILS_CATALOG = [
    'techniques' => [
        'ACRILICO' => [
            'name' => 'Acrílico',
            'description' => 'Aplicación de uñas acrílicas con selección de largo.',
            'durationMinutes' => 120,
            'usesLength' => true,
        ],
        'SOFTGEL' => [
            'name' => 'Soft gel',
            'description' => 'Aplicación de soft gel con selección de largo.',
            'durationMinutes' => 90,
            'usesLength' => true,
        ],
        'GEL_SEMI' => [
            'name' => 'Gel semipermanente',
            'description' => 'Incluye uno o dos tonos lisos.',
            'durationMinutes' => 90,
            'usesLength' => false,
            'price' => 150,
        ],
        'RUBBER_GEL' => [
            'name' => 'Rubber gel',
            'description' => 'Incluye uno o dos tonos lisos.',
            'durationMinutes' => 90,
            'usesLength' => false,
            'price' => 200,
        ],
    ],
    'lengths' => [
        1 => ['ACRILICO' => 200, 'SOFTGEL' => 150],
        2 => ['ACRILICO' => 220, 'SOFTGEL' => 170],
        3 => ['ACRILICO' => 240, 'SOFTGEL' => 190],
        4 => ['ACRILICO' => 260, 'SOFTGEL' => 210],
        5 => ['ACRILICO' => 280, 'SOFTGEL' => 230],
        6 => ['ACRILICO' => 300, 'SOFTGEL' => 250],
        7 => ['ACRILICO' => 320, 'SOFTGEL' => 270],
        8 => ['ACRILICO' => 340, 'SOFTGEL' => 290],
    ],
    'effects' => [
        3 => [
            ['id' => 'espejo', 'name' => 'Espejo'],
            ['id' => 'aurora', 'name' => 'Aurora'],
            ['id' => 'azucar', 'name' => 'Azúcar'],
            ['id' => 'sueter', 'name' => 'Suéter'],
            ['id' => 'perla', 'name' => 'Perla'],
            ['id' => 'glitter', 'name' => 'Glitter'],
            ['id' => 'carey', 'name' => 'Carey'],
            ['id' => 'blooming', 'name' => 'Blooming'],
            ['id' => 'frances', 'name' => 'Francés'],
            ['id' => 'nail_art_simple', 'name' => 'Nail art simple'],
        ],
        5 => [
            ['id' => 'ojo_de_gato', 'name' => 'Ojo de gato'],
            ['id' => 'relieve', 'name' => 'Relieve'],
            ['id' => 'encapsulado', 'name' => 'Encapsulado'],
            ['id' => 'naturaleza_muerta', 'name' => 'Naturaleza muerta'],
            ['id' => 'sticker', 'name' => 'Sticker'],
            ['id' => 'hoja_de_oro', 'name' => 'Hoja de oro'],
            ['id' => 'baby_boomer', 'name' => 'Baby boomer'],
        ],
        10 => [
            ['id' => 'diseno_3d', 'name' => '3D'],
            ['id' => 'dijes', 'name' => 'Dijes'],
            ['id' => 'cristales', 'name' => 'Diseño de cristales'],
        ],
    ],
    'removals' => [
        'ACRILICO' => ['price' => 100, 'durationMinutes' => 0],
        'SOFTGEL' => ['price' => 85, 'durationMinutes' => 0],
        'GEL_SEMI' => ['price' => 70, 'durationMinutes' => 0],
        'RUBBER_GEL' => ['price' => 70, 'durationMinutes' => 0],
    ],
    'schedule' => [
        'timezone' => 'America/Mexico_City',
        'days' => [1, 2, 3, 4, 5, 6],
        'dayNames' => ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'],
        'closedDays' => ['domingo'],
        'openingTime' => '11:00',
        'closingTime' => '20:00',
        'slotMinutes' => 30,
    ],
];
