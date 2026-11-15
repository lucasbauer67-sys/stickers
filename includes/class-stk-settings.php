<?php
/**
 * Réglages numériques : délais, rappels, purge.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Settings {

    const OPTION = 'stk_settings';

    /**
     * Valeurs par défaut validées par Lucas : expiration J+7, rappels à J+3 et J+5.
     */
    public static function defaults() {
        return array(
            'expiry_days'       => 7,
            'proposal_first'    => 3,
            'proposal_interval' => 2,
            'proposal_count'    => 2,
            'ship_first'        => 3,
            'ship_interval'     => 2,
            'ship_count'        => 2,
            'receive_first'     => 3,
            'receive_interval'  => 2,
            'receive_count'     => 2,
            'purge_enabled'     => 0,
            'purge_months'      => 24,
        );
    }

    /** Bornes de chaque réglage : array(min, max). */
    public static function bounds() {
        return array(
            'expiry_days'       => array(1, 60),
            'proposal_first'    => array(1, 60),
            'proposal_interval' => array(1, 30),
            'proposal_count'    => array(0, 10),
            'ship_first'        => array(1, 60),
            'ship_interval'     => array(1, 30),
            'ship_count'        => array(0, 10),
            'receive_first'     => array(1, 60),
            'receive_interval'  => array(1, 30),
            'receive_count'     => array(0, 10),
            'purge_enabled'     => array(0, 1),
            'purge_months'      => array(1, 120),
        );
    }

    public static function all() {
        $saved = get_option(self::OPTION, array());
        return array_merge(self::defaults(), is_array($saved) ? $saved : array());
    }

    public static function get($key) {
        $all = self::all();
        return (int) ($all[$key] ?? 0);
    }

    public static function sanitize($input) {
        $input  = is_array($input) ? $input : array();
        $clean  = array();
        foreach (self::bounds() as $key => $range) {
            $value = isset($input[$key]) ? (int) $input[$key] : ($key === 'purge_enabled' ? 0 : self::defaults()[$key]);
            $clean[$key] = max($range[0], min($range[1], $value));
        }
        return $clean;
    }

    /**
     * Jour (depuis la date de départ) du rappel nº $index (0 = premier), ou null s'il n'y en a pas.
     */
    public static function reminder_day($type, $index) {
        if ($index >= self::get($type . '_count')) {
            return null;
        }
        return self::get($type . '_first') + $index * self::get($type . '_interval');
    }
}
