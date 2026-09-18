<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * Controllo deterministico (nessun LLM coinvolto) di presenza di sezioni
 * standard nel testo di un documento, basato sui template reali del corso
 * recuperati dal corpus — non su quanto riportato dal modello, che si è
 * dimostrato inaffidabile su questo specifico giudizio (nega sezioni
 * effettivamente presenti). Il risultato viene passato al modello come fatto
 * già accertato, così non può più contraddirlo.
 *
 * Copre solo i tipi per cui è stata trovata nel corpus una struttura a
 * sezioni sufficientemente chiara e verificabile (vedi expectedSections()
 * per la fonte usata per ciascun tipo). Gli altri tipi non hanno ancora una
 * checklist: detectPresent() torna [].
 */
class DocumentSectionChecker
{
    /**
     * @return string[] Parole chiave delle sezioni attese per tipo, o [] se
     *     non ancora definito. Ogni elenco è stato verificato leggendo
     *     l'indice/le sezioni reali di un template o di un documento di
     *     riferimento del corpus (mai indovinato) — dove non è stata trovata
     *     una struttura a sezioni sufficientemente chiara, il tipo resta
     *     senza checklist piuttosto che rischiare falsi negativi.
     */
    private static function expectedSections(string $type): array
    {
        return match ($type) {
            // TemplateMinuta_Definitivo.pdf: la sezione 4 lì è dettaglio
            // degli action item, non "Discussione".
            'minuta' => ['Obiettivo', 'Comunicazioni', 'Status', 'Wrap up'],
            // TemplateAgenda_Definitivo-1.pdf.
            'agenda' => ['Obiettivo', 'Comunicazioni', 'Status', 'Discussione', 'Wrap up'],
            // Indice reale di 2024_CO4_WBS.pdf (progetto esempio).
            'wbs' => ['Introduzione', 'Ambito', 'WBS Dictionary'],
            // Indice reale di 2024_C04_LL.pdf.
            'lesson_learned' => ['Introduzione', 'Approccio'],
            // Terminologia standard vista in ITPM_11_Risk.pdf (slide teoriche).
            'risk_plan' => ['registro dei rischi', 'probabilità', 'impatto'],
            // Indice reale di 2024_CO4_Esistere_SOW.pdf.
            'sow' => ['Piano Strategico', 'Obiettivi di Business', 'Ambito del Prodotto'],
            // Sezioni numerate reali di C2024_CO4_Esistere_PC.pdf.
            'project_charter' => ['Schedule and Milestones', 'Project Manager', 'Success Criter'],
            // Indice reale di 2024_CO4_Esistere_Configuration_Management_Plan.pdf.
            'config_mgmt_plan' => ['Project Status Summary', 'Change Request'],
            // Indice reale di Responsabilità.pdf.
            'raci' => ['RACI', 'OBS'],
            // Sezioni reali viste in 2024_CO4_TC.pdf.
            'team_contract' => ['Sign-off', 'Participation'],
            default => [],
        };
    }

    /**
     * @return string[] Sottoinsieme delle sezioni attese effettivamente
     *     trovate nel testo (confronto letterale, case-insensitive).
     */
    public static function detectPresent(string $type, string $text): array
    {
        $expected = self::expectedSections($type);
        if ($expected === []) {
            return [];
        }

        $found = [];
        foreach ($expected as $keyword) {
            if (mb_stripos($text, $keyword) !== false) {
                $found[] = $keyword;
            }
        }

        return $found;
    }

    /**
     * @return string[] Sottoinsieme delle sezioni attese NON trovate nel
     *     testo (complemento di detectPresent()). Copre il problema opposto:
     *     il modello, su questo compito, tende più spesso a essere troppo
     *     permissivo (non segnala una sezione davvero assente) che a negare
     *     una sezione presente — qui il controllo automatico gli dice quali
     *     sezioni risultano assenti, spingendolo a segnalarle davvero invece
     *     di lasciarlo alla sua discrezione.
     *
     *     Limite noto: rileva solo l'assenza LETTERALE della parola chiave
     *     del template. Un elemento reale del template ma senza un'etichetta
     *     testuale prevedibile (es. la notazione a parentesi quadre
     *     issue/proposta/pro-contro della Minuta, tipo "I[1]", "+A[1.1]") non
     *     può essere verificato in modo affidabile con un semplice confronto
     *     di parole chiave — resta affidato al giudizio del modello.
     */
    public static function detectMissing(string $type, string $text): array
    {
        $expected = self::expectedSections($type);
        if ($expected === []) {
            return [];
        }

        return array_values(array_diff($expected, self::detectPresent($type, $text)));
    }
}
