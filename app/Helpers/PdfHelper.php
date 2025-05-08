<?php

namespace App\Helpers;

class PdfHelper
{
    public static function generateHtmlFromItem(array $item): string
    {
        $rows = collect($item)->map(function ($value, $key) {
            return "<tr><th>" . ucfirst(str_replace('_', ' ', $key)) . "</th><td>" . e($value) . "</td></tr>";
        })->implode('');

        return <<<HTML
            <html>
              <head>
                <style>
                  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
                  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                  td, th { border: 1px solid #ccc; padding: 4px; text-align: left; }
                  th { background-color: #f5f5f5; }
                </style>
              </head>
              <body>
                <h2>Factura Electrónica</h2>
                <table>
                  <tbody>
                    {$rows}
                  </tbody>
                </table>
              </body>
            </html>
        HTML;
    }
}
