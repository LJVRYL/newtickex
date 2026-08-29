<?php

require_once __DIR__ . '/communication_templates.php';
require_once __DIR__ . '/communication_campaigns.php';
require_once __DIR__ . '/produccion.php';
require_once __DIR__ . '/venues.php';

if (!function_exists('event_newsletters_ensure_schema')) {
    function event_newsletters_ensure_schema($pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_event_newsletters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL UNIQUE,
            created_by_admin_id INTEGER,
            subject TEXT NOT NULL,
            edition TEXT,
            location_text TEXT,
            intro_text TEXT,
            about_text TEXT,
            cta_label TEXT NOT NULL DEFAULT "Comprar entradas",
            checkout_url TEXT,
            instagram_url TEXT,
            lineup_image_path TEXT,
            template_id INTEGER,
            campaign_id INTEGER,
            published_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_event_newsletter_admin ON communication_event_newsletters(created_by_admin_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_event_newsletter_template ON communication_event_newsletters(template_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_event_newsletter_campaign ON communication_event_newsletters(campaign_id)');
        if (!event_newsletters_table_has_column($pdo, 'communication_event_newsletters', 'lineup_image_path')) {
            $pdo->exec('ALTER TABLE communication_event_newsletters ADD COLUMN lineup_image_path TEXT');
        }
        if (!event_newsletters_table_has_column($pdo, 'communication_event_newsletters', 'published_at')) {
            $pdo->exec('ALTER TABLE communication_event_newsletters ADD COLUMN published_at TEXT');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_event_newsletter_published ON communication_event_newsletters(published_at)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_event_newsletter_artists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            newsletter_id INTEGER NOT NULL,
            artist_name TEXT NOT NULL,
            review_text TEXT,
            image_path TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_event_newsletter_artist_newsletter ON communication_event_newsletter_artists(newsletter_id, sort_order, id)');
    }
}

if (!function_exists('event_newsletters_table_has_column')) {
    function event_newsletters_table_has_column($pdo, $table, $column)
    {
        $st = $pdo->query('PRAGMA table_info(' . $table . ')');
        if (!$st) return false;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['name']) && (string)$row['name'] === (string)$column) return true;
        }
        return false;
    }
}

if (!function_exists('event_newsletters_base_url')) {
    function event_newsletters_base_url()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
        if ($host !== '' && stripos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false) {
            $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
            return ($https ? 'https://' : 'http://') . $host;
        }
        return 'https://str.tickex.com.ar';
    }
}

if (!function_exists('event_newsletters_absolute_url')) {
    function event_newsletters_absolute_url($path)
    {
        $path = trim((string)$path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        return rtrim(event_newsletters_base_url(), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('event_newsletters_is_super')) {
    function event_newsletters_is_super($role)
    {
        return in_array((string)$role, array('super_admin', 'superadmin'), true);
    }
}

if (!function_exists('event_newsletters_visible_events')) {
    function event_newsletters_visible_events($pdo, $adminId, $isSuper)
    {
        $hasOwner = event_newsletters_table_has_column($pdo, 'eventos', 'creado_por_admin_id');
        $hasTrash = event_newsletters_table_has_column($pdo, 'eventos', 'borrado_en');
        $where = array();
        $params = array();
        if ($hasTrash) $where[] = 'borrado_en IS NULL';
        if (!$isSuper && $hasOwner) {
            $where[] = 'creado_por_admin_id = :aid';
            $params[':aid'] = (int)$adminId;
        }
        $sql = 'SELECT * FROM eventos';
        if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('event_newsletters_find_event')) {
    function event_newsletters_find_event($pdo, $eventId, $adminId, $isSuper)
    {
        $eventId = (int)$eventId;
        if ($eventId <= 0) return null;
        $hasOwner = event_newsletters_table_has_column($pdo, 'eventos', 'creado_por_admin_id');
        $hasTrash = event_newsletters_table_has_column($pdo, 'eventos', 'borrado_en');
        $sql = 'SELECT * FROM eventos WHERE id = :id';
        $params = array(':id' => $eventId);
        if ($hasTrash) $sql .= ' AND borrado_en IS NULL';
        if (!$isSuper && $hasOwner) {
            $sql .= ' AND creado_por_admin_id = :aid';
            $params[':aid'] = (int)$adminId;
        }
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('event_newsletters_format_date')) {
    function event_newsletters_format_date($raw)
    {
        $raw = trim((string)$raw);
        if ($raw === '') return '';
        $ts = strtotime($raw);
        if ($ts === false) return $raw;
        $months = array(1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');
        $month = isset($months[(int)date('n', $ts)]) ? $months[(int)date('n', $ts)] : date('m', $ts);
        return (int)date('j', $ts) . ' de ' . $month . ' de ' . date('Y', $ts) . ' a las ' . date('H:i', $ts) . ' hs';
    }
}

if (!function_exists('event_newsletters_event_defaults')) {
    function event_newsletters_event_defaults($pdo, $event)
    {
        $eventId = isset($event['id']) ? (int)$event['id'] : 0;
        $venue = $eventId > 0 ? get_venue_assignment($pdo, $eventId) : null;
        $location = '';
        if ($venue) {
            $location = trim((string)(isset($venue['venue_nombre']) ? $venue['venue_nombre'] : ''));
            $address = trim((string)(isset($venue['venue_direccion']) ? $venue['venue_direccion'] : ''));
            if ($address !== '') $location .= ($location !== '' ? ' — ' : '') . $address;
        }
        $flyer = '';
        if (!empty($event['flyer_filename'])) $flyer = event_newsletters_absolute_url($event['flyer_filename']);
        elseif (!empty($event['flyer'])) $flyer = event_newsletters_absolute_url($event['flyer']);
        $name = trim((string)(isset($event['nombre']) ? $event['nombre'] : 'Evento'));
        $slug = trim((string)(isset($event['slug']) ? $event['slug'] : ''));
        return array(
            'subject' => $name . ' — novedades y entradas',
            'edition' => $name,
            'location_text' => $location,
            'intro_text' => trim((string)(isset($event['descripcion']) ? $event['descripcion'] : '')),
            'about_text' => event_newsletters_default_about_text(),
            'cta_label' => 'Comprar entradas',
            'checkout_url' => event_newsletters_absolute_url('checkout_totalcoin.php?event=' . $eventId),
            'instagram_url' => 'https://www.instagram.com/saveth3rave/',
            'lineup_image_path' => '',
            'event_name' => $name,
            'event_slug' => $slug,
            'event_date' => event_newsletters_format_date(isset($event['fecha_desde']) ? $event['fecha_desde'] : ''),
            'flyer_url' => $flyer,
        );
    }
}

if (!function_exists('event_newsletters_default_about_text')) {
    function event_newsletters_default_about_text()
    {
        return "SAVE THE RAVE es un ciclo de música electrónica nacido en Buenos Aires en 2021, dedicado al EBM, el electro, el techno y otras expresiones de la escena electrónica underground. Con Aixa Yael y Chemical Pulp como residentes, el ciclo se consolidó a lo largo de más de cuatro años como un espacio de encuentro para artistas, DJs y público, priorizando una cuidada curaduría musical y una experiencia centrada en la pista de baile.\n\nA lo largo de sus ediciones han pasado por la cabina de SAVE THE RAVE artistas nacionales como Gina Demarchi, May McLaren, Happy707, Klauss, Zisko, Forello, Ana Hagen, Jessica Bellomo, JXXXO, Naiborg y Fango, además de invitados internacionales como Delia (Chile), Fabricio (Uruguay), Simetrik0002 (Chile), OHM.IO (Paraguay), Uma Scheffer (México), MM (México) y ENFAN (Francia).\n\nCon una fuerte identidad dentro de la escena independiente, SAVE THE RAVE continúa apostando por el encuentro entre referentes internacionales y artistas emergentes, manteniendo como eje principal el sonido, la comunidad y la cultura rave.";
    }
}

if (!function_exists('event_newsletters_find')) {
    function event_newsletters_find($pdo, $eventId)
    {
        event_newsletters_ensure_schema($pdo);
        $st = $pdo->prepare('SELECT * FROM communication_event_newsletters WHERE event_id = :eid LIMIT 1');
        $st->execute(array(':eid' => (int)$eventId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('event_newsletters_public_url')) {
    function event_newsletters_public_url($adminId)
    {
        return event_newsletters_absolute_url('newsletter.php?admin=' . (int)$adminId);
    }
}

if (!function_exists('event_newsletters_publish')) {
    function event_newsletters_publish($pdo, $newsletterId, $adminId)
    {
        event_newsletters_ensure_schema($pdo);
        $st = $pdo->prepare('UPDATE communication_event_newsletters SET published_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND created_by_admin_id=:admin');
        $st->execute(array(':id' => (int)$newsletterId, ':admin' => (int)$adminId));
        return $st->rowCount() === 1;
    }
}

if (!function_exists('event_newsletters_latest_published')) {
    function event_newsletters_latest_published($pdo, $adminId)
    {
        event_newsletters_ensure_schema($pdo);
        $adminId = (int)$adminId;
        if ($adminId <= 0) return null;
        $trashSql = event_newsletters_table_has_column($pdo, 'eventos', 'borrado_en') ? ' AND e.borrado_en IS NULL' : '';
        $st = $pdo->prepare('SELECT n.* FROM communication_event_newsletters n JOIN eventos e ON e.id=n.event_id WHERE n.published_at IS NOT NULL AND n.created_by_admin_id=:admin' . $trashSql . ' ORDER BY datetime(n.published_at) DESC,n.id DESC LIMIT 1');
        $st->execute(array(':admin' => $adminId));
        $newsletter = $st->fetch(PDO::FETCH_ASSOC);
        if (!$newsletter) return null;
        $eventSt = $pdo->prepare('SELECT * FROM eventos WHERE id=:id LIMIT 1');
        $eventSt->execute(array(':id' => (int)$newsletter['event_id']));
        $event = $eventSt->fetch(PDO::FETCH_ASSOC);
        if (!$event) return null;
        return array('newsletter' => $newsletter, 'event' => $event);
    }
}

if (!function_exists('event_newsletters_artists')) {
    function event_newsletters_artists($pdo, $newsletterId)
    {
        $st = $pdo->prepare('SELECT * FROM communication_event_newsletter_artists WHERE newsletter_id = :nid ORDER BY sort_order ASC, id ASC');
        $st->execute(array(':nid' => (int)$newsletterId));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('event_newsletters_default_artists')) {
    function event_newsletters_default_artists($pdo, $eventId)
    {
        $out = array();
        foreach (get_produccion_assignments($pdo, (int)$eventId) as $row) {
            $name = trim((string)(isset($row['nombre']) ? $row['nombre'] : ''));
            if ($name === '') continue;
            $out[] = array('artist_name' => $name, 'review_text' => '', 'image_path' => '', 'sort_order' => count($out));
        }
        return $out;
    }
}

if (!function_exists('event_newsletters_upload_artist_image')) {
    function event_newsletters_upload_artist_image($file, $eventId)
    {
        if (!is_array($file) || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) return array('ok' => true, 'path' => '');
        if ((int)$file['error'] !== UPLOAD_ERR_OK) return array('ok' => false, 'error' => 'No se pudo subir una de las imágenes.');
        if (!isset($file['size']) || (int)$file['size'] <= 0 || (int)$file['size'] > 5 * 1024 * 1024) return array('ok' => false, 'error' => 'Cada imagen debe pesar menos de 5 MB.');
        $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = (string)finfo_file($fi, $tmp);
                finfo_close($fi);
            }
        }
        if ($mime === '' && function_exists('mime_content_type')) $mime = (string)mime_content_type($tmp);
        $extensions = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
        if (!isset($extensions[$mime])) return array('ok' => false, 'error' => 'Formato de imagen no permitido. Usá JPG, PNG o WEBP.');
        $relativeDir = 'newsletter_uploads/event_' . (int)$eventId;
        $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true)) return array('ok' => false, 'error' => 'No se pudo crear la carpeta de imágenes del newsletter.');
        $name = 'artist_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extensions[$mime];
        $target = $absoluteDir . '/' . $name;
        if (!move_uploaded_file($tmp, $target)) return array('ok' => false, 'error' => 'No se pudo guardar la imagen del artista.');
        return array('ok' => true, 'path' => $relativeDir . '/' . $name);
    }
}

if (!function_exists('event_newsletters_escape')) {
    function event_newsletters_escape($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('event_newsletters_render')) {
    function event_newsletters_render($event, $newsletter, $artists)
    {
        $event = is_array($event) ? $event : array();
        $newsletter = is_array($newsletter) ? $newsletter : array();
        $artists = is_array($artists) ? $artists : array();
        $eventName = trim((string)(isset($event['nombre']) ? $event['nombre'] : 'Evento'));
        $date = event_newsletters_format_date(isset($event['fecha_desde']) ? $event['fecha_desde'] : '');
        $flyer = '';
        if (!empty($event['flyer_filename'])) $flyer = event_newsletters_absolute_url($event['flyer_filename']);
        elseif (!empty($event['flyer'])) $flyer = event_newsletters_absolute_url($event['flyer']);
        $edition = trim((string)(isset($newsletter['edition']) ? $newsletter['edition'] : $eventName));
        $location = trim((string)(isset($newsletter['location_text']) ? $newsletter['location_text'] : ''));
        $intro = trim((string)(isset($newsletter['intro_text']) ? $newsletter['intro_text'] : ''));
        $about = trim((string)(isset($newsletter['about_text']) ? $newsletter['about_text'] : ''));
        $cta = trim((string)(isset($newsletter['cta_label']) ? $newsletter['cta_label'] : 'Comprar entradas'));
        $checkout = trim((string)(isset($newsletter['checkout_url']) ? $newsletter['checkout_url'] : ''));
        $instagram = trim((string)(isset($newsletter['instagram_url']) ? $newsletter['instagram_url'] : ''));
        $lineupImage = trim((string)(isset($newsletter['lineup_image_path']) ? $newsletter['lineup_image_path'] : ''));
        $lineupImageUrl = $lineupImage !== '' ? event_newsletters_absolute_url($lineupImage) : '';
        $subject = trim((string)(isset($newsletter['subject']) ? $newsletter['subject'] : $eventName));

        $artistRows = array();
        $artistText = array();
        $artistNames = array();
        foreach ($artists as $artist) {
            $name = trim((string)(isset($artist['artist_name']) ? $artist['artist_name'] : ''));
            if ($name === '') continue;
            $review = trim((string)(isset($artist['review_text']) ? $artist['review_text'] : ''));
            $reviewHtml = $review !== '' ? '<p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:25px;color:#d1d1d1;">' . nl2br(event_newsletters_escape($review)) . '</p>' : '';
            $artistRows[] = '<tr><td style="padding:18px 0 14px 0;border-bottom:1px solid #1d1d1d;"><p style="margin:0;font-family:\'Arial Black\',\'Helvetica Neue\',Helvetica,Arial,sans-serif;font-size:48px;line-height:46px;color:#ffffff;font-weight:900;letter-spacing:-1px;text-transform:uppercase;">' . event_newsletters_escape($name) . '</p>' . $reviewHtml . '</td></tr>';
            $artistText[] = $name . ($review !== '' ? "\n" . $review : '');
            $artistNames[] = $name;
        }

        $logo = event_newsletters_absolute_url('templates/newsletters/assets/logo-str.png');
        $aboutImage = event_newsletters_absolute_url('templates/newsletters/assets/sobre-fiesta.jpg');
        $finalBackground = event_newsletters_absolute_url('templates/newsletters/assets/final-abajo.jpg');
        $flyerHtml = $flyer !== '' ? '<tr><td style="padding:20px 52px 22px 52px;"><img src="' . event_newsletters_escape($flyer) . '" width="576" alt="' . event_newsletters_escape($eventName) . '" style="width:100%;max-width:576px;height:auto;" /></td></tr>' : '';
        $introHtml = $intro !== '' ? '<tr><td style="padding:10px 52px 30px 52px;border-bottom:1px solid #262626;"><p style="margin:0;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif;font-size:18px;line-height:28px;color:#f0f0f0;font-weight:500;">' . nl2br(event_newsletters_escape($intro)) . '</p></td></tr>' : '';
        $lineupImageHtml = $lineupImageUrl !== '' ? '<tr><td style="padding:20px 52px 24px 52px;border-bottom:1px solid #262626;"><img src="' . event_newsletters_escape($lineupImageUrl) . '" width="576" alt="Artistas" style="width:100%;max-width:576px;height:auto;" /></td></tr>' : '';
        $aboutParagraphs = '';
        foreach (preg_split('/\R\s*\R/', $about) as $paragraph) {
            if (trim($paragraph) !== '') $aboutParagraphs .= '<p style="margin:0 0 16px 0;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif;font-size:16px;line-height:25px;color:#d1d1d1;">' . nl2br(event_newsletters_escape(trim($paragraph))) . '</p>';
        }
        $aboutHtml = '<tr><td style="padding:28px 52px 8px 52px;border-top:1px solid #262626;"><p style="margin:0 0 16px;font-family:\'Courier New\',Courier,monospace;font-size:12px;letter-spacing:1.8px;color:#9a9a9a;text-transform:uppercase;">Sobre la fiesta</p><img src="' . event_newsletters_escape($aboutImage) . '" width="576" alt="SAVE THE RAVE" style="width:100%;max-width:576px;height:auto;margin:0 0 22px 0;" />' . $aboutParagraphs . '<p style="margin:20px 0 0;font-family:\'Arial Black\',Arial,sans-serif;font-size:17px;line-height:26px;color:#ffffff;">¿Cuándo? ' . event_newsletters_escape($date) . '.<br>¿Dónde? ' . event_newsletters_escape($location) . '.</p></td></tr>';
        $ctaHtml = ($checkout !== '' && $cta !== '') ? '<tr><td background="' . event_newsletters_escape($finalBackground) . '" style="padding:0;border-top:1px solid #262626;background-image:url(\'' . event_newsletters_escape($finalBackground) . '\');background-size:cover;background-position:center;"><table role="presentation" width="100%"><tr><td align="center" style="padding:58px 32px;"><a href="' . event_newsletters_escape($checkout) . '" target="_blank" style="display:inline-block;padding:24px 34px;background:#000000;color:#ffffff;text-decoration:none;font-family:\'Arial Black\',Arial,sans-serif;font-size:34px;line-height:34px;text-transform:uppercase;">' . event_newsletters_escape($cta) . '</a></td></tr></table></td></tr>' : '';
        $instagramHtml = $instagram !== '' ? '<a href="' . event_newsletters_escape($instagram) . '" target="_blank" style="color:#bdbdbd;text-decoration:none;">Instagram</a> &nbsp;·&nbsp; ' : '';

        $topNamesHtml = !empty($artistNames) ? '<tr><td style="padding:28px 52px 18px;border-bottom:1px solid #262626;"><p style="margin:0;font-family:\'Arial Black\',Arial,sans-serif;font-size:34px;line-height:36px;color:#ffffff;font-weight:900;text-transform:uppercase;">' . event_newsletters_escape(implode(' - ', $artistNames)) . '</p><p style="margin:14px 0 0;font-family:\'Courier New\',Courier,monospace;font-size:15px;color:#cdcdcd;text-transform:uppercase;">' . ($location !== '' ? 'En ' . event_newsletters_escape($location) : '') . '</p></td></tr>' : '';
        $principalArtistHtml = !empty($artistRows) ? '<tr><td style="padding:0 52px;border-bottom:1px solid #262626;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0">' . $artistRows[0] . '</table></td></tr>' : '';
        $secondaryArtistHtml = count($artistRows) > 1 ? '<tr><td style="padding:0 52px;border-bottom:1px solid #262626;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0">' . implode('', array_slice($artistRows, 1)) . '</table></td></tr>' : '';
        $artistsBlock = '<tr><td style="padding:30px 52px 12px 52px;border-bottom:1px solid #262626;"><p style="margin:0 0 16px;font-family:\'Courier New\',Courier,monospace;font-size:12px;letter-spacing:1.8px;color:#9a9a9a;text-transform:uppercase;">Line Up</p></td></tr>' . $principalArtistHtml . $lineupImageHtml . $secondaryArtistHtml;
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . event_newsletters_escape($subject) . '</title></head><body style="margin:0;padding:0;background:#000000;color:#ffffff;"><div style="display:none;max-height:0;overflow:hidden;">' . event_newsletters_escape($subject) . '</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#000000;"><tr><td align="center" style="padding:0;"><table role="presentation" width="680" cellspacing="0" cellpadding="0" border="0" style="width:680px;max-width:100%;background:#000000;"><tr><td style="padding:42px 52px 18px;border-bottom:1px solid #262626;"><img src="' . event_newsletters_escape($logo) . '" width="396" alt="SAVE THE RAVE" style="display:block;margin:0 auto;width:396px;max-width:100%;height:auto;"><p style="margin:14px 0 0;font-family:\'Arial Black\',Arial,sans-serif;font-size:34px;line-height:36px;color:#ffffff;font-weight:800;text-transform:uppercase;">' . event_newsletters_escape($edition) . '</p><p style="margin:20px 0 0;font-family:\'Courier New\',Courier,monospace;font-size:15px;line-height:24px;color:#c3c3c3;text-transform:uppercase;">' . event_newsletters_escape($date) . ($location !== '' ? '<br>' . event_newsletters_escape($location) : '') . '</p></td></tr>' . $topNamesHtml . $flyerHtml . $introHtml . $artistsBlock . $aboutHtml . $ctaHtml . '<tr><td style="padding:24px 52px;border-top:1px solid #262626;font-family:Arial,sans-serif;font-size:13px;color:#8d8d8d;">' . $instagramHtml . '<a href="' . event_newsletters_escape(event_newsletters_base_url()) . '" target="_blank" style="color:#bdbdbd;text-decoration:none;">Tickex</a></td></tr></table></td></tr></table></body></html>';

        $textParts = array($edition, $date, $location, $intro);
        if (!empty($artistText)) $textParts[] = implode("\n\n", $artistText);
        if ($about !== '') $textParts[] = $about;
        if ($checkout !== '') $textParts[] = $cta . ': ' . $checkout;
        $text = implode("\n\n", array_values(array_filter($textParts, function ($v) { return trim((string)$v) !== ''; })));
        return array('subject' => $subject, 'body_html' => $html, 'body_text' => $text);
    }
}

if (!function_exists('event_newsletters_sync_template')) {
    function event_newsletters_sync_template($pdo, $newsletter, $event, $artists, $adminId)
    {
        communication_templates_ensure_schema($pdo);
        $rendered = event_newsletters_render($event, $newsletter, $artists);
        $templateId = isset($newsletter['template_id']) ? (int)$newsletter['template_id'] : 0;
        $name = 'Newsletter — ' . (string)(isset($event['nombre']) ? $event['nombre'] : ('Evento ' . (int)$event['id']));
        $slugBase = 'newsletter-evento-' . (int)$event['id'];
        $description = 'Generada desde el constructor del evento #' . (int)$event['id'] . '.';
        $vars = communication_variables_schema_json_from_keys(array());
        $sample = json_encode(array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($templateId > 0) {
            $stCheck = $pdo->prepare('SELECT id FROM communication_templates WHERE id = :id AND organization_id = 1 LIMIT 1');
            $stCheck->execute(array(':id' => $templateId));
            if ($stCheck->fetchColumn()) {
                $st = $pdo->prepare('UPDATE communication_templates SET name=:n, description=:d, subject_template=:s, body_html_template=:h, body_text_template=:t, variables_schema_json=:v, sample_data_json=:j, status="active", updated_at=CURRENT_TIMESTAMP WHERE id=:id');
                $st->execute(array(':n'=>$name, ':d'=>$description, ':s'=>$rendered['subject'], ':h'=>$rendered['body_html'], ':t'=>$rendered['body_text'], ':v'=>$vars, ':j'=>$sample, ':id'=>$templateId));
                return $templateId;
            }
        }
        $slug = communication_templates_unique_slug($pdo, 1, $slugBase, 0);
        $st = $pdo->prepare('INSERT INTO communication_templates (organization_id,created_by_admin_id,source_type,is_system_locked,template_type,name,slug,description,subject_template,body_html_template,body_text_template,variables_schema_json,sample_data_json,status,created_at,updated_at) VALUES (1,:aid,"event_newsletter",0,"promocion",:n,:slug,:d,:s,:h,:t,:v,:j,"active",CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
        $st->execute(array(':aid'=>(int)$adminId, ':n'=>$name, ':slug'=>$slug, ':d'=>$description, ':s'=>$rendered['subject'], ':h'=>$rendered['body_html'], ':t'=>$rendered['body_text'], ':v'=>$vars, ':j'=>$sample));
        return (int)$pdo->lastInsertId();
    }
}
