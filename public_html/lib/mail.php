<?php
declare(strict_types=1);

/**
 * Mailversand.
 *
 * Bewusst duenn: PHP-Bordmittel, kein Composer, keine fremde Bibliothek.
 * Fuer eine Bestaetigung und eine Passwort-Ruecksetzung reicht das.
 *
 * Wichtig ist der Umgang mit dem Fehlerfall. Ein Versand, der stillschweigend
 * scheitert, ist schlimmer als gar keiner: der Benutzer wartet auf eine Mail,
 * die nie kommt. send() gibt deshalb zurueck, ob der Versand angenommen wurde,
 * und der Aufrufer sagt es.
 *
 * Ist in der Konfiguration 'mail' => ['enabled' => false] gesetzt, wird nichts
 * verschickt, sondern die Nachricht in die letzte() abgelegt. Das ist der Weg
 * fuer die lokale Entwicklung und fuer ein Hosting, das nicht versenden kann -
 * die Marke laesst sich dann von Hand weitergeben.
 */
final class Mail
{
    /** @var array<string, mixed>|null die zuletzt erzeugte Nachricht */
    private static ?array $letzte = null;

    /**
     * @return array{ok: bool, message: string}
     */
    public static function send(array $config, string $to, string $subject, string $body): array
    {
        $absender = self::sender($config);

        self::$letzte = compact('to', 'subject', 'body') + ['from' => $absender];

        if (!self::enabled($config)) {
            return [
                'ok'      => false,
                'message' => 'Der Mailversand ist ausgeschaltet. Die Nachricht wurde nicht verschickt.',
            ];
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Die Adresse ist unbrauchbar.'];
        }

        $kopf = [
            'From: ' . $absender,
            'Reply-To: ' . ($config['mail']['reply_to'] ?? $absender),
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
            'X-Mailer: varcheckdb',
        ];

        // Zeilen umbrechen und Zeilenenden vereinheitlichen; manche
        // Mailserver stolpern sonst.
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        $text = wordwrap($text, 76, "\n", false);

        $ok = @mail(
            $to,
            self::encodeSubject($subject),
            $text,
            implode("\r\n", $kopf),
            '-f' . self::bareAddress($absender)
        );

        return $ok
            ? ['ok' => true, 'message' => 'Die Nachricht wurde zum Versand angenommen.']
            : ['ok' => false, 'message' => 'Der Server hat die Nachricht nicht angenommen.'];
    }

    public static function enabled(array $config): bool
    {
        return ($config['mail']['enabled'] ?? true) && function_exists('mail');
    }

    /**
     * Absender. Er muss zur Domain passen, sonst scheitert die
     * SPF-Pruefung beim Empfaenger und die Mail landet im Spam.
     */
    public static function sender(array $config): string
    {
        if (($config['mail']['from'] ?? '') !== '') {
            return (string)$config['mail']['from'];
        }

        $host = parse_url((string)($config['base_url'] ?? ''), PHP_URL_HOST) ?: 'localhost';

        return sprintf('%s <noreply@%s>', $config['site_name'] ?? 'varcheckdb', $host);
    }

    /** Betreffzeilen mit Umlauten muessen kodiert werden. */
    public static function encodeSubject(string $subject): string
    {
        return mb_check_encoding($subject, 'ASCII')
            ? $subject
            : '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    private static function bareAddress(string $sender): string
    {
        return preg_match('/<([^>]+)>/', $sender, $m) === 1 ? $m[1] : $sender;
    }

    /** Nur fuer Tests und den ausgeschalteten Versand. */
    public static function letzte(): ?array
    {
        return self::$letzte;
    }

    public static function vergessen(): void
    {
        self::$letzte = null;
    }
}
