<?php
require_once __DIR__ . '/communication_variables.php';

if (!function_exists('communication_template_renderer_apply')) {
    function communication_template_renderer_apply($templateText, $data)
    {
        $templateText = (string)$templateText;
        $data = is_array($data) ? $data : array();

        $keys = communication_variables_extract_from_content($templateText);
        if (empty($keys)) {
            return $templateText;
        }

        foreach ($keys as $key) {
            $val = '';
            if (isset($data[$key]) && !is_array($data[$key]) && !is_object($data[$key])) {
                $val = (string)$data[$key];
            }
            $templateText = preg_replace_callback(
                '/{{\s*' . preg_quote($key, '/') . '\s*}}/',
                function () use ($val) { return $val; },
                $templateText
            );
        }

        return $templateText;
    }
}

if (!function_exists('communication_template_renderer_preview')) {
    function communication_template_renderer_preview($subjectTemplate, $bodyHtmlTemplate, $bodyTextTemplate, $sampleDataJson)
    {
        $subjectTemplate = (string)$subjectTemplate;
        $bodyHtmlTemplate = (string)$bodyHtmlTemplate;
        $bodyTextTemplate = (string)$bodyTextTemplate;

        $usedVars = communication_variables_extract_from_template_parts($subjectTemplate, $bodyHtmlTemplate, $bodyTextTemplate);
        $unknownVars = communication_variables_unknown_keys($usedVars);
        $sample = communication_variables_sample_from_json($sampleDataJson);

        $missingVars = array();
        foreach ($usedVars as $k) {
            if (!isset($sample[$k]) || trim((string)$sample[$k]) === '') {
                $missingVars[$k] = true;
            }
        }

        $subjectRendered = communication_template_renderer_apply($subjectTemplate, $sample);
        $htmlRendered = communication_template_renderer_apply($bodyHtmlTemplate, $sample);
        $textRendered = communication_template_renderer_apply($bodyTextTemplate, $sample);

        return array(
            'subject' => $subjectRendered,
            'body_html' => $htmlRendered,
            'body_text' => $textRendered,
            'used_variables' => $usedVars,
            'missing_variables' => array_keys($missingVars),
            'unknown_variables' => $unknownVars,
            'sample_data' => $sample,
        );
    }
}
