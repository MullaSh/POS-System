<?php

return [
    // Cash Float per outlet, hanya untuk kembalian (C14; Confirmed Owner 2 Nov 2026).
    'cash_float' => (int) env('POS_CASH_FLOAT', 50000),

    // Lama maksimum satu delegasi persetujuan, dalam hari (Proposed, dapat diubah Owner).
    'max_delegation_days' => (int) env('POS_MAX_DELEGATION_DAYS', 31),
];
