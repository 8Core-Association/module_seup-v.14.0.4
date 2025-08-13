<?php

require_once __DIR__ . '/interna_oznaka_korisnika.class.php';
require_once __DIR__ . '/klasifikacijska_oznaka.class.php';
//require_once '../../../main.inc.php';

class Predmet_helper
{
    public static function getZaposlenici($db)
    {
        $zaposlenici = [];
        //$sql = "SELECT ime_prezime, rbr_korisnika, radno_mjesto_korisnika"; // ako zatreba radno mjesto
        $sql = "SELECT DISTINCT ime_prezime, rbr, naziv, ID, ID_ustanove";
        $sql .= " FROM " . MAIN_DB_PREFIX . "a_interna_oznaka_korisnika";
        //$sql .= " WHERE entity IN (" . getEntity('interna_oznaka_korisnika') . ")"; // Multi-company support TODO kasnije kad povezemo tablice pravilno sa svim sto treba
        $sql .= " ORDER BY ime_prezime ASC";
        $result = $db->query($sql);


        if ($result) {
            $num = $db->num_rows($result);

            for ($i = 0; $i < $num; $i++) {
                $obj = $db->fetch_object($result);

                $user = new Interna_oznaka_korisnika();
                $user->setIme_prezime($obj->ime_prezime);
                $user->setRbr_korisnika($obj->rbr);
                $user->setID($obj->ID);
                $user->setRadno_mjesto_korisnika($obj->naziv);
                $zaposlenici[] = $user;
            }

            $db->free($result);
        } else {
            dol_syslog("Error fetching user names: " . $db->lasterror(), LOG_ERR);
        }

        return $zaposlenici;
    }


    public static function getKlase($db)
    {
        $klase = [];
        $sql = "SELECT DISTINCT klasa_broj, sadrzaj, dosje_broj";
        $sql .= " FROM " . MAIN_DB_PREFIX . "a_klasifikacijska_oznaka";
        $sql .= " ORDER BY klasa_broj ASC";
        $result = $db->query($sql);

        if ($result) {
            $num = $db->num_rows($result);

            for ($i = 0; $i < $num; $i++) {
                $obj = $db->fetch_object($result);

                $klasa = new Klasifikacijska_oznaka();
                $klasa->setKlasa_br($obj->klasa_broj);
                $klasa->setSadrzaj($obj->sadrzaj);
                $klasa->setDosjeBroj($obj->dosje_broj);
                // TODO dodati vrijeme cuvanja po potrebi
                $klase[] = $klasa;
            }

            $db->free($result);
        } else {
            dol_syslog("Error fetching user names: " . $db->lasterror(), LOG_ERR);
        }

        return $klase;
    }

    public static function checkPredmetExists($db, $klasa_br, $sadrzaj, $dosje_br, $god) // Ova funkcija provjerava postoji li predmet s danim parametrima / Nek se jos koristi dok ne prebacimo sve na ID-eve, i smislimo sta cemo sa RBR predmeta
    {
        // Sanitize inputs to prevent SQL injection
        $klasa_br = $db->escape($klasa_br);
        $sadrzaj = $db->escape($sadrzaj);
        $dosje_br = $db->escape($dosje_br);
        $god = $db->escape($god);

        // Construct the query
        $sql = "SELECT COUNT(*) as count 
                FROM " . MAIN_DB_PREFIX . "a_predmet 
                WHERE klasa_br = '$klasa_br' 
                AND sadrzaj = '$sadrzaj' 
                AND dosje_broj = '$dosje_br' 
                AND godina = '$god'";

        // Execute the query
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            return ($obj->count > 0);
        } else {
            error_log("Database error: " . $db->lasterror());
            return false;
        }
    }

    public static function checkPredmetExistsById($db, $ID_predmeta) // Provjeri dal predmet s ID_predmeta postoji, Ne koristi se u aplikaciji trenutno, ali hoce nakon prebacivanja na ID-eve, i rjesavanje RBR predmeta 
    {
        // Validate and sanitize the ID
        $ID_predmeta = (int)$ID_predmeta;
        if ($ID_predmeta <= 0) {
            error_log("Invalid predmet ID: $ID_predmeta");
            return false;
        }

        // Construct the query using primary key
        $sql = "SELECT COUNT(*) as count 
            FROM " . MAIN_DB_PREFIX . "a_predmet 
            WHERE ID_predmet = " . $ID_predmeta;

        // Execute the query
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            return ($obj->count > 0);
        } else {
            error_log("Database error: " . $db->lasterror());
            return false;
        }
    }



    public static function getNextPredmetRbr($db, $klasa_br, $sadrzaj, $dosje_br, $god)
    {
        // Fetch the maxipredmet_rbr for the given combination
        $sql = "SELECT COALESCE(MAX(CAST(predmet_rbr AS UNSIGNED)), 0) AS max_rbr
                FROM " . MAIN_DB_PREFIX . "a_predmet
                WHERE klasa_br = '" . $db->escape($klasa_br) . "'
                AND sadrzaj = '" . $db->escape($sadrzaj) . "'
                AND dosje_broj = '" . $db->escape($dosje_br) . "'
                AND godina = '" . $db->escape($god) . "'";

        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            $next_rbr = (int)$obj->max_rbr + 1; // Vracamo iduci redni broj za ovu kombinaciju
            return $next_rbr;
        } else {
            error_log("Database error: " . $db->lasterror());
            return 1; // Fallback to 1 if there's a DB error
        }
    }

    public static function insertPredmet($db, $klasa_br, $sadrzaj, $dosje_br, $god, $predmet_rbr, $naziv, $id_ustanove, $id_zaposlenik, $id_klasifikacijske_oznake, $vrijeme_cuvanja, $stranka = null, $datum_otvaranja = null)
    {

        dol_syslog("Inserting predmet with parameters: klasa_br=$klasa_br, sadrzaj=$sadrzaj, dosje_br=$dosje_br, god=$god, predmet_rbr=$predmet_rbr, naziv=$naziv, id_ustanove=$id_ustanove, id_zaposlenik=$id_zaposlenik, id_klasifikacijske_oznake=$id_klasifikacijske_oznake, vrijeme_cuvanja=$vrijeme_cuvanja, stranka=" . ($stranka ?? 'NULL') . ", datum_otvaranja=" . ($datum_otvaranja ?? 'NULL'), LOG_INFO);
        // Sanitize inputs
        $klasa_br = $db->escape($klasa_br);
        $sadrzaj = $db->escape($sadrzaj);
        $dosje_br = $db->escape($dosje_br);
        $god = $db->escape($god);
        $predmet_rbr = $db->escape($predmet_rbr);
        $naziv = $db->escape($naziv);
        $id_ustanove = (int)$id_ustanove;
        $id_zaposlenik = (int)$id_zaposlenik;
        $id_klasifikacijske_oznake = (int)$id_klasifikacijske_oznake;
        $vrijeme_cuvanja = (int)$vrijeme_cuvanja;

        // Handle stranka value
        $strankaValue = 'NULL';
        if ($stranka !== null) {
            $stranka = $db->escape($stranka);
            $strankaValue = "'$stranka'";
        }

        $SQL_datum_otvaranja = "'" . $datum_otvaranja . "'";

        // Construct the insert query
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "a_predmet (
                klasa_br, 
                sadrzaj, 
                dosje_broj, 
                godina, 
                predmet_rbr, 
                naziv_predmeta, 
                ID_ustanove,
                ID_interna_oznaka_korisnika,
                ID_klasifikacijske_oznake,
                vrijeme_cuvanja,
                stranka_kreirala,
                tstamp_created
            ) 
            VALUES (
                '$klasa_br', 
                '$sadrzaj', 
                '$dosje_br', 
                '$god', 
                '$predmet_rbr', 
                '$naziv', 
                $id_ustanove,
                $id_zaposlenik,
                $id_klasifikacijske_oznake,
                $vrijeme_cuvanja,
                $strankaValue,
                $SQL_datum_otvaranja
            )";

        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog("Insert failed: " . $db->lasterror(), LOG_ERR);
            return false;
        }
        return $db->last_insert_id(MAIN_DB_PREFIX . 'a_predmet', 'ID_predmeta');;
    }

    public static function fetchDropdownData($db, $langs, &$klasaOptions, &$klasaMapJson, &$zaposlenikOptions)
    {
        // Fetch klase and zaposlenici
        $klase = self::getKlase($db);
        $zaposlenici = self::getZaposlenici($db);

        // Build klasa options and map
        $klasaMap = [];
        $klasaOptions = '<option value="">' . htmlspecialchars($langs->trans("Odaberi Klasu"), ENT_QUOTES) . '</option>';

        foreach ($klase as $item) {
            $safeKlasaBroj = htmlspecialchars($item->getKlasa_br(), ENT_QUOTES);
            $safeSadrzaj = htmlspecialchars($item->getSadrzaj(), ENT_QUOTES);
            $safeDosjeBroj = htmlspecialchars($item->getDosjeBroj(), ENT_QUOTES);

            if (!isset($klasaMap[$safeKlasaBroj])) {
                $klasaOptions .= '<option value="' . $safeKlasaBroj . '">' . $safeKlasaBroj . '</option>';
                $klasaMap[$safeKlasaBroj] = [];
            }

            // Initialize nested arrays if necessary
            if (!isset($klasaMap[$safeKlasaBroj][$safeSadrzaj])) {
                $klasaMap[$safeKlasaBroj][$safeSadrzaj] = [];
            }
            // Add dosjeBroj to the sadrzaj entry
            if (!in_array($safeDosjeBroj, $klasaMap[$safeKlasaBroj][$safeSadrzaj])) {
                $klasaMap[$safeKlasaBroj][$safeSadrzaj][] = $safeDosjeBroj;
            }
        }

        // Encode map for JavaScript and escape for HTML
        $klasaMapJson = json_encode($klasaMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        // Build zaposlenik options
        $zaposlenikOptions = '<option value="">' . htmlspecialchars($langs->trans("Odaberi zaposlenika"), ENT_QUOTES) . '</option>';
        foreach ($zaposlenici as $zaposlenik) {
            $id = $zaposlenik->getID();
            $imePrezime = htmlspecialchars($zaposlenik->getIme_prezime(), ENT_QUOTES, 'UTF-8');
            //$rbrKorisnika = htmlspecialchars($zaposlenik->getRbr_korisnika(), ENT_QUOTES, 'UTF-8');   TODO ako zatreba rbr korisnika (testiranje trenutno)
            $zaposlenikOptions .= '<option value="' . htmlspecialchars($id, ENT_QUOTES) . '">' . $imePrezime . '</option>';
        }

        // TODO ovo cemo kasnije povezati s korisnikom i predmetom i dodanim aktovima
        // Get oznaka ustanove 
        $code_ustanova = '';
        $sql = "SELECT code_ustanova FROM " . MAIN_DB_PREFIX . "a_oznaka_ustanove LIMIT 1";
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql)) {
            $obj = $db->fetch_object($resql);
            $code_ustanova = $obj->code_ustanova;
        }
    }


    public static function fetchUploadedDocuments($db, $conf, &$documentTableHTML, $langs, $caseId = null)
    {
        // First check if predmet is archived
        if ($caseId) {
            $sql = "SELECT ID_arhive FROM " . MAIN_DB_PREFIX . "a_arhiva WHERE ID_predmeta = " . (int)$caseId;
            $resql = $db->query($sql);
            if ($resql && $db->num_rows($resql) > 0) {
                // Predmet is archived, look in archive location
                $sql = "SELECT ef.urbroj, ef.filename, ef.filepath, ef.date_c, u.firstname, u.lastname
                FROM " . MAIN_DB_PREFIX . "ecm_files ef
                LEFT JOIN " . MAIN_DB_PREFIX . "user u ON ef.fk_user_c = u.rowid
                WHERE ef.entity = " . ((int) $conf->entity) . "
                AND ef.filepath LIKE 'SEUP/Arhiva%'
                AND ef.filepath LIKE '%predmet_" . $db->escape($caseId) . "%'
                ORDER BY ef.date_c DESC";
            } else {
                // Predmet is not archived, look in normal location
                $sql = "SELECT ef.urbroj, ef.filename, ef.filepath, ef.date_c, u.firstname, u.lastname
                FROM " . MAIN_DB_PREFIX . "ecm_files ef
                LEFT JOIN " . MAIN_DB_PREFIX . "user u ON ef.fk_user_c = u.rowid
                WHERE ef.entity = " . ((int) $conf->entity) . "
                AND ef.filepath LIKE 'SEUP/predmet_" . $db->escape($caseId) . "%'
                ORDER BY ef.date_c DESC";
            }
        } else {
            // Show all non-archived documents
            $sql = "SELECT ef.urbroj, ef.filename, ef.filepath, ef.date_c, u.firstname, u.lastname
            FROM " . MAIN_DB_PREFIX . "ecm_files ef
            LEFT JOIN " . MAIN_DB_PREFIX . "user u ON ef.fk_user_c = u.rowid
            WHERE ef.entity = " . ((int) $conf->entity) . "
            AND ef.filepath LIKE 'SEUP/predmet_%'
            AND ef.filepath NOT LIKE 'SEUP/Arhiva%'
            ORDER BY ef.date_c DESC";
        }

        dol_syslog("Documents SQL: " . $sql, LOG_DEBUG); // Debugging line

        $resql = $db->query($sql);
        $doc_list = [];

        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $doc = new stdClass();
                $doc->filename = $obj->filename;
                $doc->filepath = $obj->filepath;
                $doc->urbroj = $obj->urbroj;
                $doc->date_creation = $db->jdate($obj->date_c);
                $doc->lastname = $obj->lastname;
                $doc->firstname = $obj->firstname;
                $doc_list[] = $doc;
            }
        }

        // Build HTML table (unchanged from your original)
        $documentTableRows = '';
        if (!empty($doc_list)) {
            foreach ($doc_list as $doc) {
                $documentTableRows .= '<tr>';
                $documentTableRows .= '<td>' . dol_escape_htmltag($doc->urbroj ?: 'TBD') . '</td>';
                $documentTableRows .= '<td>' . dol_escape_htmltag($doc->filename) . '</td>';
                $documentTableRows .= '<td>' . dol_print_date($doc->date_creation, 'dayhour') . '</td>';
                $documentTableRows .= '<td>' . dolGetFirstLastname($doc->lastname, $doc->firstname) . '</td>';
                $documentTableRows .= '<td class="text-center"><a href="' . DOL_URL_ROOT . '/document.php?modulepart=ecm&file=' . urlencode($doc->filepath . '/' . $doc->filename) . '" class="btn btn-outline-primary btn-sm" style="min-width: 80px;" target="_blank"><i class="fa fa-download"></i></a></td>';
                $documentTableRows .= '</tr>';
            }

            $documentTableHTML = '
        <div class="mt-4">
            <h5>' . $langs->trans("UploadaniDokumenti") . '</h5>
            <table class="table table-sm table-bordered mt-2">
                <thead>
                    <tr>
                        <th>' . $langs->trans("Naziv datoteke") . '</th>
                        <th>' . $langs->trans("Datum Uploada") . '</th>
                        <th>' . $langs->trans("Upload Korisnik") . '</th>
                        <th>' . $langs->trans("Download") . '</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $documentTableRows . '
                </tbody>
            </table>
        </div>';
        } else {
            $documentTableHTML = '<div class="mt-4 alert alert-info">' . $langs->trans("NoDocumentsFound") . '</div>';
        }
    }

    /**
     * Create database tables if they don't exist
     */
    public static function createSeupDatabaseTables($db)
    {
        // Define all tables to create (in dependency order)
        $tables = [
            'a_oznaka_ustanove' => [
                'sql' => "CREATE TABLE IF NOT EXISTS `" . MAIN_DB_PREFIX . "a_oznaka_ustanove` (
                `ID_ustanove` TINYINT(3) UNSIGNED NOT NULL AUTO_INCREMENT,
                `singleton` TINYINT(1) NOT NULL DEFAULT 1 CHECK (`singleton` = 1),
                `code_ustanova` VARCHAR(8) NOT NULL,
                `name_ustanova` VARCHAR(255) NOT NULL,
                `tstamp_azuriranja` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `tstamp_stvaranja` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`ID_ustanove`),
                UNIQUE KEY `unique_singleton` (`singleton`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'a_interna_oznaka_korisnika' => [
                'sql' => "CREATE TABLE IF NOT EXISTS `" . MAIN_DB_PREFIX . "a_interna_oznaka_korisnika` (
                `ID` TINYINT(3) UNSIGNED NOT NULL AUTO_INCREMENT,
                `ID_ustanove` TINYINT(3) UNSIGNED NOT NULL,
                `ime_prezime` VARCHAR(60) NOT NULL,
                `rbr` TINYINT(3) UNSIGNED NOT NULL,
                `naziv` VARCHAR(50) NOT NULL,
                `tstamp_kreiranja` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `tstamp_azuriranja` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`ID`),
                KEY `fk_interna_ustanova` (`ID_ustanove`),
                CONSTRAINT `fk_interna_ustanova` FOREIGN KEY (`ID_ustanove`) 
                    REFERENCES `" . MAIN_DB_PREFIX . "a_oznaka_ustanove` (`ID_ustanove`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'a_klasifikacijska_oznaka' => [
                'sql' => "CREATE TABLE IF NOT EXISTS `" . MAIN_DB_PREFIX . "a_klasifikacijska_oznaka` (
                `ID_klasifikacijske_oznake` INT(4) UNSIGNED NOT NULL AUTO_INCREMENT,
                `ID_ustanove` TINYINT(3) UNSIGNED NOT NULL,                
                `klasa_broj` SMALLINT(3) UNSIGNED ZEROFILL NOT NULL,
                `sadrzaj` TINYINT(2) UNSIGNED ZEROFILL NOT NULL,
                `dosje_broj` TINYINT(2) UNSIGNED ZEROFILL NOT NULL,
                `vrijeme_cuvanja` TINYINT(2) UNSIGNED NOT NULL COMMENT 'Vrijeme u godinama. 0 = TRAJNO!',
                `opis_klasifikacijske_oznake` TEXT NOT NULL,
                `tst_kreiranja` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `tst_azuriranja` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`ID_klasifikacijske_oznake`),
                UNIQUE KEY `unique_klasa_sadrzaj_dosje` (`klasa_broj`, `sadrzaj`, `dosje_broj`),
                KEY `fk_klasifikacija_ustanova` (`ID_ustanove`),
                CONSTRAINT `fk_klasifikacija_ustanova` FOREIGN KEY (`ID_ustanove`) 
                    REFERENCES `" . MAIN_DB_PREFIX . "a_oznaka_ustanove` (`ID_ustanove`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'a_predmet' => [
                'sql' => "CREATE TABLE IF NOT EXISTS `" . MAIN_DB_PREFIX . "a_predmet` (
                `ID_predmeta` INT(11) NOT NULL AUTO_INCREMENT,
                `ID_ustanove` TINYINT(3) UNSIGNED NOT NULL,
                `ID_interna_oznaka_korisnika` TINYINT(3) UNSIGNED NOT NULL,
                `ID_klasifikacijske_oznake` INT(4) UNSIGNED NOT NULL,
                `stranka_kreirala` VARCHAR(255) DEFAULT NULL,
                `klasa_br` VARCHAR(3) NOT NULL,
                `sadrzaj` VARCHAR(2) NOT NULL,
                `dosje_broj` VARCHAR(2) NOT NULL,
                `godina` VARCHAR(2) NOT NULL,
                `predmet_rbr` VARCHAR(2) NOT NULL,
                `naziv_predmeta` VARCHAR(500) NOT NULL,
                `vrijeme_cuvanja` TINYINT(2) NOT NULL,
                `tstamp_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `tstamp_updated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`ID_predmeta`),
                UNIQUE KEY `klasa_br` (`klasa_br`, `sadrzaj`, `dosje_broj`, `godina`, `predmet_rbr`),
                KEY `fk_predmet_ustanova` (`ID_ustanove`),
                KEY `fk_predmet_interna` (`ID_interna_oznaka_korisnika`),
                KEY `fk_predmet_klasifikacija` (`ID_klasifikacijske_oznake`),
                CONSTRAINT `fk_predmet_interna` FOREIGN KEY (`ID_interna_oznaka_korisnika`) 
                    REFERENCES `" . MAIN_DB_PREFIX . "a_interna_oznaka_korisnika` (`ID`),
                CONSTRAINT `fk_predmet_klasifikacija` FOREIGN KEY (`ID_klasifikacijske_oznake`) 
                    REFERENCES `" . MAIN_DB_PREFIX . "a_klasifikacijska_oznaka` (`ID_klasifikacijske_oznake`),
                CONSTRAINT `fk_predmet_ustanova` FOREIGN KEY (`ID_ustanove`) 
                    REFERENCES `" . MAIN_DB_PREFIX . "a_oznaka_ustanove` (`ID_ustanove`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'a_tagovi' => [
                'sql' => "CREATE TABLE IF NOT EXISTS `" . MAIN_DB_PREFIX . "a_tagovi` (
                `rowid` INT(11) NOT NULL AUTO_INCREMENT,
                `tag` VARCHAR(128) NOT NULL,
                `color` VARCHAR(20) DEFAULT 'blue',
                `entity` INT(11) NOT NULL DEFAULT 1,
                `date_creation` DATETIME NOT NULL,
                `tms` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `fk_user_creat` INT(11) NOT NULL,
                PRIMARY KEY (`rowid`),
                UNIQUE KEY `tag_entity` (`tag`, `entity`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'a_predmet_tagovi' => [
                'sql' => "CREATE TABLE IF NOT EXISTS `" . MAIN_DB_PREFIX . "a_predmet_tagovi` (
                `rowid` INT(11) NOT NULL AUTO_INCREMENT,
                `fk_predmet` INT(11) NOT NULL,
                `fk_tag` INT(11) NOT NULL,
                PRIMARY KEY (`rowid`),
                UNIQUE KEY `unique_predmet_tag` (`fk_predmet`, `fk_tag`),
                KEY `fk_tag` (`fk_tag`),
                CONSTRAINT `fk_predmet` FOREIGN KEY (`fk_predmet`) 
                    REFERENCES `" . MAIN_DB_PREFIX . "a_predmet` (`ID_predmeta`) ON DELETE CASCADE,
                CONSTRAINT `fk_tag` FOREIGN KEY (`fk_tag`) 
                    REFERENCES `" . MAIN_DB_PREFIX . "a_tagovi` (`rowid`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'partnership' => [
                'sql' => "CREATE TABLE IF NOT EXISTS `" . MAIN_DB_PREFIX . "partnership` (
                `rowid` int(11) NOT NULL AUTO_INCREMENT,
                `entity` int(11) NOT NULL DEFAULT 1,
                `ref` varchar(128) NOT NULL DEFAULT '(PROV)',
                `status` smallint(6) NOT NULL DEFAULT 0,
                `fk_type` int(11) NOT NULL DEFAULT 0,
                `fk_soc` int(11) DEFAULT NULL,
                `fk_member` int(11) DEFAULT NULL,
                `email_partnership` varchar(64) DEFAULT NULL,
                `date_partnership_start` date NOT NULL,
                `date_partnership_end` date DEFAULT NULL,
                `reason_decline_or_cancel` text DEFAULT NULL,
                `date_creation` datetime NOT NULL,
                `fk_user_creat` int(11) DEFAULT NULL,
                `tms` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                `fk_user_modif` int(11) DEFAULT NULL,
                `note_private` text DEFAULT NULL,
                `note_public` text DEFAULT NULL,
                `last_main_doc` varchar(255) DEFAULT NULL,
                `url_to_check` varchar(255) DEFAULT NULL,
                `count_last_url_check_error` int(11) DEFAULT 0,
                `last_check_backlink` datetime DEFAULT NULL,
                `ip` varchar(250) DEFAULT NULL,
                `import_key` varchar(14) DEFAULT NULL,
                `model_pdf` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`rowid`),
                UNIQUE KEY `uk_partnership_ref` (`ref`,`entity`),
                UNIQUE KEY `uk_fk_type_fk_soc` (`fk_type`,`fk_soc`,`date_partnership_start`),
                UNIQUE KEY `uk_fk_type_fk_member` (`fk_type`,`fk_member`,`date_partnership_start`),
                KEY `idx_partnership_entity` (`entity`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;"
            ],

            'a_predmet_stranka' => [
                'sql' => "CREATE TABLE IF NOT EXISTS `" . MAIN_DB_PREFIX . "a_predmet_stranka` (
                `ID_predmeta` INT(11) NOT NULL,
                `fk_soc` INT(11) NOT NULL,
                `role` ENUM('creator','opponent','witness','other') DEFAULT 'creator',
                `date_stranka_opened` DATE NULL DEFAULT NULL COMMENT 'Date when stranka opened the predmet',
                `tstamp_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `tstamp_modified` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`ID_predmeta`, `fk_soc`),
                KEY `fk_soc` (`fk_soc`),
                CONSTRAINT `fk_predmetstr_predmet` 
                    FOREIGN KEY (`ID_predmeta`) 
                    REFERENCES `" . MAIN_DB_PREFIX . "a_predmet` (`ID_predmeta`)
                    ON DELETE CASCADE,
                CONSTRAINT `fk_predmetstr_societe` 
                    FOREIGN KEY (`fk_soc`) 
                    REFERENCES `" . MAIN_DB_PREFIX . "societe` (`rowid`)
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'a_arhiva' => [
                'sql' => "CREATE TABLE IF NOT EXISTS `" . MAIN_DB_PREFIX . "a_arhiva` (
                `ID_arhive` INT(11) NOT NULL AUTO_INCREMENT,
                `ID_predmeta` INT(11) NOT NULL,
                `klasa_predmeta` VARCHAR(50) NOT NULL COMMENT 'Format: 001-01/25-01/1',
                `naziv_predmeta` VARCHAR(500) NOT NULL,
                `lokacija_arhive` VARCHAR(255) NOT NULL COMMENT 'Path to archive folder',
                `broj_dokumenata` INT(11) DEFAULT 0,
                `razlog_arhiviranja` TEXT DEFAULT NULL,
                `datum_arhiviranja` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `fk_user_arhivirao` INT(11) NOT NULL,
                `status_arhive` ENUM('active','deleted','corrupted') DEFAULT 'active',
                `metadata_json` TEXT DEFAULT NULL COMMENT 'Additional metadata as JSON',
                PRIMARY KEY (`ID_arhive`),
                UNIQUE KEY `unique_predmet_arhiva` (`ID_predmeta`),
                KEY `idx_klasa_predmeta` (`klasa_predmeta`),
                KEY `idx_datum_arhiviranja` (`datum_arhiviranja`),
                KEY `fk_user_arhivirao` (`fk_user_arhivirao`),
                CONSTRAINT `fk_arhiva_predmet` 
                    FOREIGN KEY (`ID_predmeta`) 
                    REFERENCES `" . MAIN_DB_PREFIX . "a_predmet` (`ID_predmeta`)
                    ON DELETE CASCADE,
                CONSTRAINT `fk_arhiva_user` 
                    FOREIGN KEY (`fk_user_arhivirao`) 
                    REFERENCES `" . MAIN_DB_PREFIX . "user` (`rowid`)
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],
        ];

        // Disable foreign key checks temporarily
        $db->query('SET FOREIGN_KEY_CHECKS=0');

        // Create tables in the correct order
        foreach ($tables as $table => $data) {
            $full_table = MAIN_DB_PREFIX . $table;

            // Check if table exists
            $res = $db->query("SELECT 1 FROM information_schema.tables 
                          WHERE table_schema = DATABASE()
                          AND table_name = '" . $db->escape($full_table) . "'");

            if ($res && $db->num_rows($res)) {
                dol_syslog("Skipping existing table: {$full_table}", LOG_DEBUG);
                continue;
            }

            // Create table if it doesn't exist
            if (!$db->query($data['sql'])) {
                dol_syslog("Failed to create table: {$full_table} - Error: " . $db->lasterror(), LOG_ERR);
                // Re-enable FK checks before exiting
                $db->query('SET FOREIGN_KEY_CHECKS=1');
                return false;
            }

            dol_syslog("Created table: {$full_table}", LOG_INFO);
        }

        // Apply ALTER to llx_ecm_files
        $alterTable = MAIN_DB_PREFIX . "ecm_files";
        $alterSQL = "ALTER TABLE " . $alterTable . " 
                 ADD COLUMN urbroj VARCHAR(50) NULL DEFAULT NULL AFTER filename";

        // Check if column already exists
        $res = $db->query("SHOW COLUMNS FROM `$alterTable` LIKE 'urbroj'");
        if ($res && $db->num_rows($res)) {
            dol_syslog("Column 'urbroj' already exists in {$alterTable}", LOG_DEBUG);
        } else {
            if (!$db->query($alterSQL)) {
                dol_syslog("Failed to alter table {$alterTable}: " . $db->lasterror(), LOG_ERR);
                $db->query('SET FOREIGN_KEY_CHECKS=1');
                return false;
            }
            dol_syslog("Added 'urbroj' column to {$alterTable}", LOG_INFO);
        }

        // Re-enable foreign key checks
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        
        // Add color column to existing a_tagovi table if it doesn't exist
        $alterTagsTable = MAIN_DB_PREFIX . "a_tagovi";
        $alterTagsSQL = "ALTER TABLE " . $alterTagsTable . " 
                     ADD COLUMN color VARCHAR(20) DEFAULT 'blue' AFTER tag";

        // Check if column already exists
        $res = $db->query("SHOW COLUMNS FROM `$alterTagsTable` LIKE 'color'");
        if ($res && $db->num_rows($res)) {
            dol_syslog("Column 'color' already exists in {$alterTagsTable}", LOG_DEBUG);
        } else {
            if (!$db->query($alterTagsSQL)) {
                dol_syslog("Failed to alter table {$alterTagsTable}: " . $db->lasterror(), LOG_ERR);
                return false;
            }
            dol_syslog("Added 'color' column to {$alterTagsTable}", LOG_INFO);
        }

        return true;
    }

    public static function getUstanovaByZaposlenik($db, $zaposlenik_id)
    {
        $zaposlenik_id = (int)$zaposlenik_id;
        $sql = "SELECT ID_ustanove 
            FROM " . MAIN_DB_PREFIX . "a_interna_oznaka_korisnika 
            WHERE ID = $zaposlenik_id";

        $resql = $db->query($sql);
        if ($resql && $obj = $db->fetch_object($resql)) {
            return $obj;
        }
        return false;
    }

    public static function getKlasifikacijskaOznaka($db, $klasa_br, $sadrzaj, $dosje_br)
    {
        $klasa_br = $db->escape($klasa_br);
        $sadrzaj = $db->escape($sadrzaj);
        $dosje_br = $db->escape($dosje_br);

        $sql = "SELECT ID_klasifikacijske_oznake, vrijeme_cuvanja 
            FROM " . MAIN_DB_PREFIX . "a_klasifikacijska_oznaka 
            WHERE klasa_broj = '$klasa_br' 
            AND sadrzaj = '$sadrzaj' 
            AND dosje_broj = '$dosje_br' 
            LIMIT 1";

        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            return $db->fetch_object($resql);
        }
        return false;
    }

    public static function getCaseDetails($db, $caseId)
    {
        $sql = "SELECT 
                p.ID_predmeta,
                CONCAT(p.klasa_br, '-', p.sadrzaj, '/', p.godina, '-', p.dosje_broj, '/', p.predmet_rbr) as klasa,
                p.naziv_predmeta,
                DATE_FORMAT(p.tstamp_created, '%d.%m.%Y') as datum_otvaranja,
                u.name_ustanova,
                k.ime_prezime
            FROM " . MAIN_DB_PREFIX . "a_predmet p
            LEFT JOIN " . MAIN_DB_PREFIX . "a_oznaka_ustanove u ON p.ID_ustanove = u.ID_ustanove
            LEFT JOIN " . MAIN_DB_PREFIX . "a_interna_oznaka_korisnika k ON p.ID_interna_oznaka_korisnika = k.ID
            WHERE p.ID_predmeta = " . (int)$caseId;

        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            return $db->fetch_object($resql);
        }
        return false;
    }

    public static function buildOrderByKlasa($sortField, $sortOrder, $tableAlias = 'p')
    {
        $sortOrder = ($sortOrder === 'DESC') ? 'DESC' : 'ASC';

        if ($sortField === 'klasa_br') {
            return "ORDER BY 
                CAST({$tableAlias}.klasa_br AS UNSIGNED),
                CAST({$tableAlias}.sadrzaj AS UNSIGNED),
                CAST({$tableAlias}.godina AS UNSIGNED),
                CAST({$tableAlias}.dosje_broj AS UNSIGNED),
                CAST({$tableAlias}.predmet_rbr AS UNSIGNED) {$sortOrder}";
        } elseif ($sortField === 'ID_predmeta') {
            return "ORDER BY CAST({$tableAlias}.ID_predmeta AS UNSIGNED) {$sortOrder}";
        } else {
            return "ORDER BY {$sortField} {$sortOrder}";
        }
    }

    public static function buildKlasifikacijaOrderBy($sortField, $sortOrder, $tableAlias = 'ko')
    {
        $sortOrder = ($sortOrder === 'DESC') ? 'DESC' : 'ASC';

        if ($sortField === 'klasa_br') {
            return "ORDER BY 
                CAST({$tableAlias}.klasa_broj AS UNSIGNED),
                CAST({$tableAlias}.sadrzaj AS UNSIGNED),
                CAST({$tableAlias}.dosje_broj AS UNSIGNED) {$sortOrder}";
        } elseif ($sortField === 'ID_klasifikacijske_oznake') {
            return "ORDER BY CAST({$tableAlias}.ID_klasifikacijske_oznake AS UNSIGNED) {$sortOrder}";
        } elseif ($sortField === 'vrijeme_cuvanja') {
            return "ORDER BY CAST({$tableAlias}.vrijeme_cuvanja AS UNSIGNED) {$sortOrder}";
        } else {
            return "ORDER BY {$tableAlias}.{$sortField} {$sortOrder}";
        }
    }


    public static function societeExists($db, $soc_id)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe 
            WHERE rowid = " . (int)$soc_id;
        $res = $db->query($sql);
        return ($res && $db->num_rows($res) > 0);
    }

    public static function klasifikacijaExists($db, $id)
    {
        $sql = "SELECT ID_klasifikacijske_oznake 
            FROM " . MAIN_DB_PREFIX . "a_klasifikacijska_oznaka 
            WHERE ID_klasifikacijske_oznake = " . (int)$id;
        $res = $db->query($sql);
        return ($res && $db->num_rows($res) > 0);
    }

    /**
     * Archive a predmet - move documents and create archive record
     */
    public static function archivePredmet($db, $conf, $user, $predmet_id, $razlog = null)
    {
        $predmet_id = (int)$predmet_id;
        
        // Start transaction
        $db->begin();
        
        try {
            // 1. Get predmet details
            $sql = "SELECT 
                        p.ID_predmeta,
                        CONCAT(p.klasa_br, '-', p.sadrzaj, '/', p.godina, '-', p.dosje_broj, '/', p.predmet_rbr) as klasa,
                        p.naziv_predmeta,
                        p.klasa_br,
                        p.sadrzaj,
                        p.dosje_broj,
                        p.godina,
                        p.predmet_rbr
                    FROM " . MAIN_DB_PREFIX . "a_predmet p
                    WHERE p.ID_predmeta = $predmet_id";
            
            $resql = $db->query($sql);
            if (!$resql || $db->num_rows($resql) == 0) {
                throw new Exception("Predmet not found");
            }
            
            $predmet = $db->fetch_object($resql);
            
            // 2. Check if already archived
            $sql = "SELECT ID_arhive FROM " . MAIN_DB_PREFIX . "a_arhiva WHERE ID_predmeta = $predmet_id";
            $resql = $db->query($sql);
            if ($resql && $db->num_rows($resql) > 0) {
                throw new Exception("Predmet je već arhiviran");
            }
            
            // 3. Create archive directory structure
            $archive_base = DOL_DATA_ROOT . '/ecm/SEUP/Arhiva/';
            $archive_dir = $archive_base . $predmet->klasa . '/';
            
            if (!dol_mkdir($archive_dir)) {
                throw new Exception("Cannot create archive directory: $archive_dir");
            }
            
            // 4. Move documents from predmet to archive
            $source_dir = DOL_DATA_ROOT . '/ecm/SEUP/predmet_' . $predmet_id . '/';
            $moved_files = 0;
            
            if (is_dir($source_dir)) {
                $files = scandir($source_dir);
                foreach ($files as $file) {
                    if ($file != '.' && $file != '..') {
                        $source_file = $source_dir . $file;
                        $dest_file = $archive_dir . $file;
                        
                        if (copy($source_file, $dest_file)) {
                            unlink($source_file); // Remove original
                            $moved_files++;
                        }
                    }
                }
                
                // Remove empty source directory
                if (count(scandir($source_dir)) == 2) { // Only . and ..
                    rmdir($source_dir);
                }
            }
            
            // 5. Update ECM files table
            $sql = "UPDATE " . MAIN_DB_PREFIX . "ecm_files 
                    SET filepath = 'SEUP/Arhiva/" . $db->escape($predmet->klasa) . "'
                    WHERE filepath LIKE 'SEUP/predmet_" . $predmet_id . "%'";
            
            if (!$db->query($sql)) {
                throw new Exception("Failed to update ECM files: " . $db->lasterror());
            }
            
            // 6. Create metadata JSON
            $metadata = [
                'predmet_id' => $predmet_id,
                'klasa' => $predmet->klasa,
                'naziv' => $predmet->naziv_predmeta,
                'arhiviran' => date('Y-m-d H:i:s'),
                'korisnik' => $user->firstname . ' ' . $user->lastname,
                'broj_dokumenata' => $moved_files,
                'source_directory' => 'SEUP/predmet_' . $predmet_id
            ];
            
            // Save metadata file
            $metadata_file = $archive_dir . 'metadata.json';
            file_put_contents($metadata_file, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // 7. Insert archive record
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "a_arhiva (
                        ID_predmeta,
                        klasa_predmeta,
                        naziv_predmeta,
                        lokacija_arhive,
                        broj_dokumenata,
                        razlog_arhiviranja,
                        fk_user_arhivirao,
                        metadata_json
                    ) VALUES (
                        $predmet_id,
                        '" . $db->escape($predmet->klasa) . "',
                        '" . $db->escape($predmet->naziv_predmeta) . "',
                        'SEUP/Arhiva/" . $db->escape($predmet->klasa) . "/',
                        $moved_files,
                        " . ($razlog ? "'" . $db->escape($razlog) . "'" : "NULL") . ",
                        " . $user->id . ",
                        '" . $db->escape(json_encode($metadata)) . "'
                    )";
            
            if (!$db->query($sql)) {
                throw new Exception("Failed to create archive record: " . $db->lasterror());
            }
            
            // 8. Mark predmet as archived (add status column if needed)
            $sql = "UPDATE " . MAIN_DB_PREFIX . "a_predmet 
                    SET tstamp_updated = CURRENT_TIMESTAMP 
                    WHERE ID_predmeta = $predmet_id";
            
            $db->query($sql); // Non-critical update
            
            $db->commit();
            
            return [
                'success' => true,
                'message' => "Predmet uspješno arhiviran",
                'archive_path' => $archive_dir,
                'files_moved' => $moved_files,
                'klasa' => $predmet->klasa
            ];
            
        } catch (Exception $e) {
            $db->rollback();
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get archived predmeti
     */
    public static function getArchivedPredmeti($db, $limit = 50)
    {
        $sql = "SELECT 
                    a.ID_arhive,
                    a.klasa_predmeta,
                    a.naziv_predmeta,
                    a.broj_dokumenata,
                    a.datum_arhiviranja,
                    a.razlog_arhiviranja,
                    u.firstname,
                    u.lastname
                FROM " . MAIN_DB_PREFIX . "a_arhiva a
                LEFT JOIN " . MAIN_DB_PREFIX . "user u ON a.fk_user_arhivirao = u.rowid
                WHERE a.status_arhive = 'active'
                ORDER BY a.datum_arhiviranja DESC
                LIMIT $limit";
        
        $resql = $db->query($sql);
        $archived = [];
        
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $archived[] = $obj;
            }
        }
        
        return $archived;
    }

    /**
     * Restore a predmet from archive back to active
     */
    public static function restorePredmet($db, $conf, $user, $arhiva_id)
    {
        $arhiva_id = (int)$arhiva_id;
        
        // Start transaction
        $db->begin();
        
        try {
            // 1. Get archive details
            $sql = "SELECT 
                        a.ID_arhive,
                        a.ID_predmeta,
                        a.klasa_predmeta,
                        a.naziv_predmeta,
                        a.lokacija_arhive,
                        a.broj_dokumenata
                    FROM " . MAIN_DB_PREFIX . "a_arhiva a
                    WHERE a.ID_arhive = $arhiva_id
                    AND a.status_arhive = 'active'";
            
            $resql = $db->query($sql);
            if (!$resql || $db->num_rows($resql) == 0) {
                throw new Exception("Archive record not found");
            }
            
            $arhiva = $db->fetch_object($resql);
            
            // 2. Create active predmet directory
            $active_dir = DOL_DATA_ROOT . '/ecm/SEUP/predmet_' . $arhiva->ID_predmeta . '/';
            
            if (!dol_mkdir($active_dir)) {
                throw new Exception("Cannot create active directory: $active_dir");
            }
            
            // 3. Move documents from archive back to active
            $archive_dir = DOL_DATA_ROOT . '/ecm/' . $arhiva->lokacija_arhive;
            $moved_files = 0;
            
            if (is_dir($archive_dir)) {
                $files = scandir($archive_dir);
                foreach ($files as $file) {
                    if ($file != '.' && $file != '..' && $file != 'metadata.json') {
                        $source_file = $archive_dir . $file;
                        $dest_file = $active_dir . $file;
                        
                        if (copy($source_file, $dest_file)) {
                            unlink($source_file); // Remove from archive
                            $moved_files++;
                        }
                    }
                }
            }
            
            // 4. Update ECM files table
            $sql = "UPDATE " . MAIN_DB_PREFIX . "ecm_files 
                    SET filepath = 'SEUP/predmet_" . $arhiva->ID_predmeta . "'
                    WHERE filepath = '" . $db->escape(rtrim($arhiva->lokacija_arhive, '/')) . "'";
            
            if (!$db->query($sql)) {
                throw new Exception("Failed to update ECM files: " . $db->lasterror());
            }
            
            // 5. Delete archive record
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "a_arhiva 
                    WHERE ID_arhive = $arhiva_id";
            
            if (!$db->query($sql)) {
                throw new Exception("Failed to delete archive record: " . $db->lasterror());
            }
            
            // 6. Clean up empty archive directory
            if (is_dir($archive_dir)) {
                $remaining_files = array_diff(scandir($archive_dir), ['.', '..']);
                if (count($remaining_files) <= 1) { // Only metadata.json or empty
                    // Remove metadata.json if exists
                    $metadata_file = $archive_dir . 'metadata.json';
                    if (file_exists($metadata_file)) {
                        unlink($metadata_file);
                    }
                    rmdir($archive_dir);
                }
            }
            
            $db->commit();
            
            return [
                'success' => true,
                'message' => "Predmet uspješno vraćen iz arhive",
                'files_moved' => $moved_files,
                'klasa' => $arhiva->klasa_predmeta
            ];
            
        } catch (Exception $e) {
            $db->rollback();
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Permanently delete an archive
     */
    public static function deleteArchive($db, $conf, $user, $arhiva_id)
    {
        $arhiva_id = (int)$arhiva_id;
        
        // Start transaction
        $db->begin();
        
        try {
            // 1. Get archive details
            $sql = "SELECT 
                        a.ID_arhive,
                        a.ID_predmeta,
                        a.klasa_predmeta,
                        a.lokacija_arhive,
                        a.broj_dokumenata
                    FROM " . MAIN_DB_PREFIX . "a_arhiva a
                    WHERE a.ID_arhive = $arhiva_id
                    AND a.status_arhive = 'active'";
            
            $resql = $db->query($sql);
            if (!$resql || $db->num_rows($resql) == 0) {
                throw new Exception("Archive record not found");
            }
            
            $arhiva = $db->fetch_object($resql);
            
            // 2. Delete all files from archive directory
            $archive_dir = DOL_DATA_ROOT . '/ecm/' . $arhiva->lokacija_arhive;
            $deleted_files = 0;
            
            if (is_dir($archive_dir)) {
                $files = scandir($archive_dir);
                foreach ($files as $file) {
                    if ($file != '.' && $file != '..') {
                        $file_path = $archive_dir . $file;
                        if (unlink($file_path)) {
                            $deleted_files++;
                        }
                    }
                }
                
                // Remove directory
                rmdir($archive_dir);
            }
            
            // 3. Delete ECM files records
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "ecm_files 
                    WHERE filepath = '" . $db->escape(rtrim($arhiva->lokacija_arhive, '/')) . "'";
            
            $db->query($sql); // Non-critical if fails
            
            // 4. Delete predmet record
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "a_predmet 
                    WHERE ID_predmeta = " . $arhiva->ID_predmeta;
            
            if (!$db->query($sql)) {
                throw new Exception("Failed to delete predmet record: " . $db->lasterror());
            }
            
            // 5. Delete archive record
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "a_arhiva 
                    WHERE ID_arhive = $arhiva_id";
            
            if (!$db->query($sql)) {
                throw new Exception("Failed to delete archive record: " . $db->lasterror());
            }
            
            $db->commit();
            
            return [
                'success' => true,
                'message' => "Arhiva je trajno obrisana",
                'files_deleted' => $deleted_files,
                'klasa' => $arhiva->klasa_predmeta
            ];
            
        } catch (Exception $e) {
            $db->rollback();
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Sync filesystem files with ECM database for a specific predmet
     */
    public static function syncPredmetFiles($db, $conf, $user, $predmet_id)
    {
        $predmet_id = (int)$predmet_id;
        
        try {
            // 1. Check if predmet is archived
            $sql = "SELECT ID_arhive FROM " . MAIN_DB_PREFIX . "a_arhiva WHERE ID_predmeta = $predmet_id";
            $resql = $db->query($sql);
            $is_archived = ($resql && $db->num_rows($resql) > 0);
            
            // 2. Determine correct directory
            if ($is_archived) {
                // Get archive location
                $sql = "SELECT lokacija_arhive FROM " . MAIN_DB_PREFIX . "a_arhiva WHERE ID_predmeta = $predmet_id";
                $resql = $db->query($sql);
                if ($resql && $obj = $db->fetch_object($resql)) {
                    $dir_path = DOL_DATA_ROOT . '/ecm/' . $obj->lokacija_arhive;
                    $ecm_filepath = rtrim($obj->lokacija_arhive, '/');
                } else {
                    throw new Exception("Archive location not found");
                }
            } else {
                $dir_path = DOL_DATA_ROOT . '/ecm/SEUP/predmet_' . $predmet_id . '/';
                $ecm_filepath = 'SEUP/predmet_' . $predmet_id;
            }
            
            // 3. Check if directory exists
            if (!is_dir($dir_path)) {
                return [
                    'success' => true,
                    'message' => 'Nema direktorija za sync',
                    'files_added' => 0,
                    'files_removed' => 0
                ];
            }
            
            // 4. Get files from filesystem
            $filesystem_files = [];
            $files = scandir($dir_path);
            foreach ($files as $file) {
                if ($file != '.' && $file != '..' && $file != 'metadata.json') {
                    $filesystem_files[] = $file;
                }
            }
            
            // 5. Get files from ECM database
            $sql = "SELECT filename FROM " . MAIN_DB_PREFIX . "ecm_files 
                    WHERE filepath = '" . $db->escape($ecm_filepath) . "'
                    AND entity = " . $conf->entity;
            
            $resql = $db->query($sql);
            $database_files = [];
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    $database_files[] = $obj->filename;
                }
            }
            
            // 6. Find missing files (in filesystem but not in database)
            $missing_files = array_diff($filesystem_files, $database_files);
            
            // 7. Find orphaned files (in database but not in filesystem)
            $orphaned_files = array_diff($database_files, $filesystem_files);
            
            $files_added = 0;
            $files_removed = 0;
            
            // 8. Add missing files to ECM database
            require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';
            
            foreach ($missing_files as $filename) {
                $file_path = $dir_path . $filename;
                
                // Skip if file doesn't actually exist or is not readable
                if (!is_file($file_path) || !is_readable($file_path)) {
                    continue;
                }
                
                $ecmfile = new EcmFiles($db);
                $ecmfile->filepath = $ecm_filepath;
                $ecmfile->filename = $filename;
                $ecmfile->label = $filename;
                $ecmfile->entity = $conf->entity;
                $ecmfile->gen_or_uploaded = 'external'; // Mark as externally added
                $ecmfile->description = 'Auto-synced from filesystem';
                $ecmfile->fk_user_c = $user->id;
                $ecmfile->fk_user_m = $user->id;
                
                // Get file info
                $file_info = stat($file_path);
                if ($file_info) {
                    $ecmfile->date_c = $file_info['ctime'];
                    $ecmfile->date_m = $file_info['mtime'];
                }
                
                // Set MIME type
                if (function_exists('mime_content_type')) {
                    $ecmfile->filetype = mime_content_type($file_path);
                } else {
                    $ecmfile->filetype = dol_mimetype($filename);
                }
                
                // Generate urbroj for external files
                $ecmfile->urbroj = 'EXT-' . date('Ymd') . '-' . sprintf('%04d', rand(1, 9999));
                
                $result = $ecmfile->create($user);
                if ($result > 0) {
                    $files_added++;
                    dol_syslog("Added external file to ECM: $filename", LOG_INFO);
                } else {
                    dol_syslog("Failed to add external file: $filename - " . $ecmfile->error, LOG_ERR);
                }
            }
            
            // 9. Remove orphaned database entries
            foreach ($orphaned_files as $filename) {
                $sql = "DELETE FROM " . MAIN_DB_PREFIX . "ecm_files 
                        WHERE filepath = '" . $db->escape($ecm_filepath) . "'
                        AND filename = '" . $db->escape($filename) . "'
                        AND entity = " . $conf->entity;
                
                if ($db->query($sql)) {
                    $files_removed++;
                    dol_syslog("Removed orphaned ECM entry: $filename", LOG_INFO);
                }
            }
            
            return [
                'success' => true,
                'message' => "Sync završen uspješno",
                'files_added' => $files_added,
                'files_removed' => $files_removed,
                'total_files' => count($filesystem_files),
                'is_archived' => $is_archived
            ];
            
        } catch (Exception $e) {
            dol_syslog("File sync error: " . $e->getMessage(), LOG_ERR);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get file statistics for a predmet
     */
    public static function getFileStats($db, $conf, $predmet_id)
    {
        $predmet_id = (int)$predmet_id;
        
        // Check if archived
        $sql = "SELECT lokacija_arhive FROM " . MAIN_DB_PREFIX . "a_arhiva WHERE ID_predmeta = $predmet_id";
        $resql = $db->query($sql);
        
        if ($resql && $obj = $db->fetch_object($resql)) {
            // Archived predmet
            $dir_path = DOL_DATA_ROOT . '/ecm/' . $obj->lokacija_arhive;
            $ecm_filepath = rtrim($obj->lokacija_arhive, '/');
        } else {
            // Active predmet
            $dir_path = DOL_DATA_ROOT . '/ecm/SEUP/predmet_' . $predmet_id . '/';
            $ecm_filepath = 'SEUP/predmet_' . $predmet_id;
        }
        
        // Count filesystem files
        $filesystem_count = 0;
        if (is_dir($dir_path)) {
            $files = array_diff(scandir($dir_path), ['.', '..', 'metadata.json']);
            $filesystem_count = count($files);
        }
        
        // Count database files
        $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "ecm_files 
                WHERE filepath = '" . $db->escape($ecm_filepath) . "'
                AND entity = " . $conf->entity;
        
        $resql = $db->query($sql);
        $database_count = 0;
        if ($resql && $obj = $db->fetch_object($resql)) {
            $database_count = (int)$obj->count;
        }
        
        return [
            'filesystem_count' => $filesystem_count,
            'database_count' => $database_count,
            'needs_sync' => ($filesystem_count != $database_count),
            'directory_path' => $dir_path
        ];
    }
}