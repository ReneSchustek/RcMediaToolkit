<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Ermittelt, wo im Shop Medien referenziert werden — die einzige Quelle dafür.
 *
 * Die Liste wird aus dem Schema abgeleitet und nicht gepflegt. Eine gepflegte Liste veraltet
 * unbemerkt: sie kennt weder neue Kern-Tabellen noch die Tabellen fremder Plugins, und wenn eine
 * Spalte wegfällt, fällt das niemandem auf. Für den Garbage Collector wäre jede Lücke ein
 * gelöschtes, aber referenziertes Medium.
 *
 * Drei Arten von Quellen:
 *  - Fremdschlüssel auf `media.id` (aus `information_schema`)
 *  - Text-/JSON-Spalten, in denen Medien-IDs ohne Fremdschlüssel stehen
 *  - alle `custom_fields`-Spalten des Shops
 */
final class MediaReferenceSources
{
    private const MEDIA_TABLE = 'media';
    private const CUSTOM_FIELDS_COLUMN = 'custom_fields';

    /**
     * Kindtabellen des Mediums selbst. Sie beschreiben das Medium, sie verwenden es nicht —
     * ohne diesen Ausschluss wäre kein Medium jemals eine Waise.
     */
    private const OWN_CHILD_TABLES = ['media_translation', 'media_thumbnail', 'media_tag'];

    /**
     * Quellen ohne Fremdschlüssel: hier stehen Medien-IDs im Text oder im JSON. Nicht jede Spalte
     * existiert in jeder Shopware-Version — was fehlt, wird übersprungen.
     *
     * @var list<array{table: string, column: string}>
     */
    private const TEXT_SOURCE_CANDIDATES = [
        ['table' => 'cms_slot_translation', 'column' => 'config'],
        ['table' => 'mail_template_translation', 'column' => 'content_html'],
        ['table' => 'mail_template_translation', 'column' => 'content_plain'],
        ['table' => 'theme', 'column' => 'base_config'],
        ['table' => 'theme', 'column' => 'config_values'],
        ['table' => 'theme', 'column' => 'theme_json'],
        ['table' => 'system_config', 'column' => 'configuration_value'],
    ];

    /** @var list<array{table: string, column: string}>|null */
    private ?array $foreignKeyColumns = null;

    /** @var list<array{table: string, column: string}>|null */
    private ?array $textColumns = null;

    /** @var list<string>|null */
    private ?array $customFieldsTables = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Alle Spalten, die per Fremdschlüssel auf `media.id` zeigen — Kern wie Plugins.
     *
     * @return list<array{table: string, column: string}>
     * @throws DbalException
     */
    public function foreignKeyColumns(): array
    {
        if ($this->foreignKeyColumns !== null) {
            return $this->foreignKeyColumns;
        }

        $schema = $this->connection->getDatabase();
        if ($schema === null) {
            return $this->foreignKeyColumns = [];
        }

        /** @var list<array{TABLE_NAME: string, COLUMN_NAME: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = :schema AND REFERENCED_TABLE_SCHEMA = :schema '
            . 'AND REFERENCED_TABLE_NAME = :mediaTable AND REFERENCED_COLUMN_NAME = :mediaColumn '
            . 'ORDER BY TABLE_NAME, COLUMN_NAME',
            [
                'schema' => $schema,
                'mediaTable' => self::MEDIA_TABLE,
                'mediaColumn' => 'id',
            ],
        );

        $columns = [];
        foreach ($rows as $row) {
            if (\in_array($row['TABLE_NAME'], self::OWN_CHILD_TABLES, true)) {
                continue;
            }
            $columns[] = ['table' => $row['TABLE_NAME'], 'column' => $row['COLUMN_NAME']];
        }

        return $this->foreignKeyColumns = $columns;
    }

    /**
     * Text-/JSON-Spalten mit Medien-IDs ohne Fremdschlüssel, gefiltert auf das, was es hier gibt.
     *
     * @return list<array{table: string, column: string}>
     * @throws DbalException
     */
    public function textColumns(): array
    {
        if ($this->textColumns !== null) {
            return $this->textColumns;
        }

        $schema = $this->connection->getDatabase();
        if ($schema === null) {
            return $this->textColumns = [];
        }

        $candidateTables = [];
        foreach (self::TEXT_SOURCE_CANDIDATES as $candidate) {
            $candidateTables[$candidate['table']] = true;
        }

        /** @var list<array{TABLE_NAME: string, COLUMN_NAME: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = :schema AND TABLE_NAME IN (:tables)',
            [
                'schema' => $schema,
                'tables' => array_keys($candidateTables),
            ],
            [
                'tables' => ArrayParameterType::STRING,
            ],
        );

        $existing = [];
        foreach ($rows as $row) {
            $existing[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] = true;
        }

        $columns = [];
        foreach (self::TEXT_SOURCE_CANDIDATES as $candidate) {
            if (isset($existing[$candidate['table'] . '.' . $candidate['column']])) {
                $columns[] = $candidate;
            }
        }

        return $this->textColumns = $columns;
    }

    /**
     * Alle Tabellen mit einer `custom_fields`-Spalte — dort können Medien-IDs im JSON stehen.
     *
     * @return list<string>
     * @throws DbalException
     */
    public function customFieldsTables(): array
    {
        if ($this->customFieldsTables !== null) {
            return $this->customFieldsTables;
        }

        $schema = $this->connection->getDatabase();
        if ($schema === null) {
            return $this->customFieldsTables = [];
        }

        /** @var list<string> $tables */
        $tables = $this->connection->fetchFirstColumn(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = :schema AND COLUMN_NAME = :column ORDER BY TABLE_NAME',
            [
                'schema' => $schema,
                'column' => self::CUSTOM_FIELDS_COLUMN,
            ],
        );

        return $this->customFieldsTables = $tables;
    }
}
