<?php

declare(strict_types=1);

return [
    'database_reachable' => 'Databázové pripojenie [:connection] je dostupné.',
    'database_unreachable' => 'Databázové pripojenie [:connection] je nedostupné.',

    'cache_ok' => 'Úložisko cache [:store] odpovedá.',
    'cache_unreachable' => 'Úložisko cache [:store] je nedostupné.',
    'cache_mismatch' => 'Úložisko cache [:store] vrátilo neočakávanú hodnotu.',

    'queue_ok' => 'Fronta [:connection] je dostupná (čakajúce úlohy: :size).',
    'queue_unreachable' => 'Fronta [:connection] je nedostupná.',
    'queue_backlog' => 'Vo fronte [:connection] čaká priveľa úloh (čakajúce úlohy: :size).',

    'storage_ok' => 'Na disku [:disk] je dostatok voľného miesta.',
    'storage_warning' => 'Na disku [:disk] dochádza voľné miesto.',
    'storage_low' => 'Voľné miesto na disku [:disk] kleslo pod minimum.',
    'storage_unreadable' => 'Voľné miesto na disku [:disk] sa nepodarilo zistiť.',

    'http_ok' => 'Endpoint [:url] odpovedal úspešne.',
    'http_unreachable' => 'K endpointu [:url] sa nepodarilo pripojiť.',
    'http_bad_status' => 'Endpoint [:url] vrátil stavový kód :status.',
    'http_slow' => 'Endpoint [:url] odpovedal pomaly (:latency ms).',
];
