<?php

namespace App\Support;

use Illuminate\Http\Response;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Shuchkin\SimpleXLSXGen;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación tabular reutilizable (CSV, Excel, Word).
 */
class TabularExport
{
    /**
     * @param  list<string>  $labels
     * @param  list<list<string>>  $rows
     */
    public static function csv(array $labels, array $rows, string $baseName): StreamedResponse
    {
        return response()->streamDownload(function () use ($labels, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $labels);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $baseName.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  list<string>  $labels
     * @param  list<list<string>>  $rows
     */
    public static function xlsx(array $labels, array $rows, string $baseName, string $sheetTitle = 'Datos'): Response
    {
        $data = array_merge([$labels], $rows);
        $xlsx = SimpleXLSXGen::fromArray($data, $sheetTitle);

        return response($xlsx->__toString(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$baseName.'.xlsx"',
        ]);
    }

    /**
     * @param  list<string>  $labels
     * @param  list<list<string>>  $rows
     */
    public static function docx(
        array $labels,
        array $rows,
        string $baseName,
        string $documentTitle,
        ?string $subtitle = null,
    ): Response {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'marginLeft' => 600,
            'marginRight' => 600,
        ]);
        $section->addText($documentTitle, ['bold' => true, 'size' => 14]);
        $section->addText($subtitle ?? 'Generado: '.now()->format('d/m/Y H:i'), ['size' => 9, 'italic' => true]);
        $section->addTextBreak(1);

        $table = $section->addTable([
            'borderSize' => 6,
            'borderColor' => '999999',
            'cellMargin' => 80,
            'unit' => 'dxa',
        ]);

        $table->addRow();
        foreach ($labels as $label) {
            $table->addCell(2000)->addText((string) $label, ['bold' => true, 'size' => 7]);
        }
        foreach ($rows as $row) {
            $table->addRow();
            foreach ($row as $cell) {
                $text = str_replace(["\r\n", "\n", "\r"], ' ', (string) $cell);
                $table->addCell(2000)->addText($text, ['size' => 7]);
            }
        }

        $tmpPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.uniqid('export-tabular-', true).'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tmpPath);

        return response()->download($tmpPath, $baseName.'.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }
}
