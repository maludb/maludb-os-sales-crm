<?php
declare(strict_types=1);

/** The custom fields section of any record's form (shared/custom-fields.php renders it). */
function custom_fields_section(PDO $pdo, string $kind, array $values, string $prefix, array $errors = []): string
{
    $defs = custom_field_defs($pdo, $kind);
    return $defs === [] ? '' : view('shared/custom-fields.php', ['defs' => $defs, 'values' => $values, 'prefix' => $prefix, 'errors' => $errors]);
}
