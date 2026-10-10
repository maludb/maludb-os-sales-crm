<?php
declare(strict_types=1);

/**
 * The CSV a person uploads (leads-and-conversion.md §4): UTF-8 or Latin-1 (sniffed; a BOM dropped), `,` or `;` or a tab (sniffed from the header line),
 * a header row. csv_sniff() reads the start of the file for the mapping step and the preview; csv_rows() yields every data row as [row number, values].
 */

/** [delimiter, encoding, headers[], preview (the first 20 rows as lists), rows (an estimate — the lines after the header)]. */
function csv_sniff(string $path): array
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new DomainException('The file could not be read.');
    }
    $head = (string) fread($fh, 65536);
    fclose($fh);
    $head = preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? $head;
    $encoding = mb_check_encoding(substr($head, 0, (int) (strrpos($head, "\n") ?: strlen($head))), 'UTF-8') ? 'UTF-8' : 'ISO-8859-1';
    $first = strtok($head, "\r\n") ?: '';
    $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($counts);
    $delimiter = (string) array_key_first($counts);
    if ($counts[$delimiter] === 0) { $delimiter = ','; }
    $headers = [];
    $preview = [];
    foreach (csv_rows($path, $delimiter, $encoding, true) as [$n, $values]) {
        if ($n === 0) { $headers = array_map(static fn ($h): string => trim((string) $h), $values); continue; }
        $preview[] = $values;
        if (count($preview) >= 20) { break; }
    }
    $lines = 0;
    $fh = fopen($path, 'rb');
    while (!feof($fh)) { $lines += substr_count((string) fread($fh, 1048576), "\n"); }
    fclose($fh);
    return ['delimiter' => $delimiter, 'encoding' => $encoding, 'headers' => $headers, 'preview' => $preview, 'rows' => max(0, $lines - 1 + (str_ends_with(trim($head), "\n") ? 0 : 0))];
}

/** Every row as [row number (the header is 0), values[]], converted to UTF-8; blank lines skipped. $withHeader yields row 0. */
function csv_rows(string $path, string $delimiter, string $encoding, bool $withHeader = false): Generator
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new DomainException('The file could not be read.');
    }
    $n = -1;
    while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
        if ($row === [null] || $row === ['']) { continue; }
        $n++;
        if ($n === 0) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
            if (!$withHeader) { continue; }
        }
        if ($encoding !== 'UTF-8') {
            $row = array_map(static fn ($v) => $v === null ? null : mb_convert_encoding((string) $v, 'UTF-8', $encoding), $row);
        }
        yield [$n, array_map(static fn ($v) => $v === null ? '' : trim((string) $v), $row)];
    }
    fclose($fh);
}

/** A header in the words the mapping compares: lower case, letters and digits joined by underscores ("E-mail Address" → e_mail_address). */
function csv_norm(string $h): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $h) ?: $h)), '_');
}
