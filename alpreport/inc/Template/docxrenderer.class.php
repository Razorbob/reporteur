<?php

class PluginAlpreportDocxRenderer
{
    /**
     * @param array<string,string> $placeholderMap
     * @param array<string,array{headers:string[],rows:array<int,string[]>}> $blockMap
     * @param array<string,array<int,array<string,string>>> $rowTableData
     * @param callable(string):string $normalizeXml
     */
    public static function render(
        string $templatePath,
        array $placeholderMap,
        array $blockMap,
        array $rowTableData,
        callable $normalizeXml
    ): string {
        if (!is_file($templatePath)) {
            throw new RuntimeException('Template not found: ' . $templatePath);
        }
        if (!is_readable($templatePath)) {
            throw new RuntimeException('Template is not readable: ' . $templatePath);
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive extension is required to render DOCX files.');
        }

        $templateSize = @filesize($templatePath);
        if ($templateSize === false || $templateSize <= 0) {
            throw new RuntimeException('Template file is empty or unreadable: ' . $templatePath);
        }

        $bytes = @file_get_contents($templatePath);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Could not read template bytes from ' . $templatePath);
        }

        $tempDir = sys_get_temp_dir();
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            throw new RuntimeException('System temp dir is not writable: ' . $tempDir);
        }
        $docxPath = $tempDir . DIRECTORY_SEPARATOR . 'alpreport_' . bin2hex(random_bytes(8)) . '.docx';

        if (@file_put_contents($docxPath, $bytes) !== strlen($bytes)) {
            @unlink($docxPath);
            throw new RuntimeException('Could not write template to temp file: ' . $docxPath);
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($docxPath);
        if ($openResult !== true) {
            $head = bin2hex(substr($bytes, 0, 4));
            @unlink($docxPath);

            $hint = '';
            if (strncmp($head, '504b', 4) !== 0) {
                $hint = ' The file does not start with the ZIP signature (50 4B 03 04),'
                    . ' so it is not a real .docx package. In Microsoft Word, use'
                    . ' "File > Save As > Word Document (*.docx)" — not "Word XML Document"'
                    . ' or "Strict Open XML" — then re-upload.';
            }

            throw new RuntimeException(
                'Could not open template as DOCX archive (ZipArchive error code ' . $openResult
                . ', file size ' . $templateSize . ' bytes, first 4 bytes 0x' . $head . ').'
                . $hint
            );
        }

        try {
            $targets = self::xmlTargets($zip);
            $touched = 0;

            foreach ($targets as $entry) {
                $xml = $zip->getFromName($entry);
                if ($xml === false) {
                    continue;
                }

                $xml = $normalizeXml($xml);
                $xml = PluginAlpreportXmlRowTableRenderer::render($xml, $rowTableData);
                $xml = PluginAlpreportXmlBlockTableRenderer::render($xml, $blockMap, $placeholderMap);
                $xml = PluginAlpreportXmlScalarRenderer::render($xml, $placeholderMap);

                if (!$zip->addFromString($entry, $xml)) {
                    throw new RuntimeException('Failed to write replaced content into DOCX entry: ' . $entry);
                }
                $touched++;
            }

            if ($touched === 0) {
                throw new RuntimeException('Template did not contain word/document.xml — not a valid DOCX.');
            }
        } catch (Throwable $e) {
            $zip->close();
            @unlink($docxPath);
            throw $e;
        }

        if (!$zip->close()) {
            @unlink($docxPath);
            throw new RuntimeException('Failed to finalize DOCX archive.');
        }

        return $docxPath;
    }

    /**
     * @return string[]
     */
    private static function xmlTargets(ZipArchive $zip): array
    {
        $targets = ['word/document.xml'];
        for ($i = 1; $i <= 20; $i++) {
            $headerName = 'word/header' . $i . '.xml';
            if ($zip->locateName($headerName) !== false) {
                $targets[] = $headerName;
            }
            $footerName = 'word/footer' . $i . '.xml';
            if ($zip->locateName($footerName) !== false) {
                $targets[] = $footerName;
            }
        }

        return $targets;
    }
}
