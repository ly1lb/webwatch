<?php
declare(strict_types=1);

/**
 * Išsiunčia pranešimą pagal stebėjimo nustatymą:
 *  auto  – push; jei nepavyko pristatyti nė vienam įrenginiui – el. paštu
 *  push  – tik push
 *  email – tik el. paštu
 *  both  – push ir el. paštu
 *  none  – nesiųsti (tik istorija)
 */
function notify_user(string $mode, string $title, string $body, string $link, string $tag = 'webwatch'): array
{
    $result = ['push' => 0, 'email' => false, 'errors' => []];
    if ($mode === 'none') {
        return $result;
    }
    if ($mode === 'auto' || $mode === 'push' || $mode === 'both') {
        $result['push'] = webpush_broadcast([
            'title' => $title,
            'body' => mb_substr($body, 0, 400),
            'url' => $link,
            'tag' => $tag,
        ]);
    }
    $needEmail = $mode === 'email' || $mode === 'both' || ($mode === 'auto' && $result['push'] === 0);
    if ($needEmail) {
        $to = (string)setting('email_to', '');
        if ($to === '') {
            $result['errors'][] = 'Nenurodytas el. paštas nustatymuose';
        } else {
            $text = $body . "\n\n" . $link . "\n\n— WebWatch";
            $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;font-size:15px;color:#111">'
                . '<h2 style="margin:0 0 12px;font-size:18px">' . h($title) . '</h2>'
                . '<pre style="white-space:pre-wrap;font-family:inherit;background:#f4f4f6;padding:12px;border-radius:8px">' . h($body) . '</pre>'
                . '<p><a href="' . h($link) . '" style="display:inline-block;background:#4f46e5;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Atidaryti WebWatch</a></p>'
                . '</div>';
            $r = send_mail($to, $title, $text, $html);
            $result['email'] = $r['ok'];
            if (!$r['ok']) {
                $result['errors'][] = $r['error'];
                ww_log('error', 'El. laiškas nepavyko: ' . $r['error']);
            }
        }
    }
    return $result;
}
