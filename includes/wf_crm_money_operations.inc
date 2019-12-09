<?php

class wf_crm_money_operations {

  /**
   * The digits after decimal precision we are
   * using, it is big enough to make sure
   * rounding will work fine.
   */
  const CALCULATION_PRECISION_SCALE = 8;

  /**
   * CiviCRM stores all financial amounts
   * inside database as decimal(10,2), which
   * means only two digits are allowed after
   * the decimal and thus we are choosing the
   * same number for rounding the final amount.
   */
  const ROUNDING_DECIMAL_SCALE = 2;

  public static function add($leftOperand, $rightOperand) {
    $result = bcadd($leftOperand, $rightOperand, self::CALCULATION_PRECISION_SCALE);
    return self::round($result);
  }

  public static function subtract($leftOperand, $rightOperand) {
    $result = bcsub($leftOperand, $rightOperand, self::CALCULATION_PRECISION_SCALE);
    return self::round($result);
  }

  public static function multiply($leftOperand, $rightOperand) {
    $result = bcmul($leftOperand, $rightOperand, self::CALCULATION_PRECISION_SCALE);
    return self::round($result);
  }

  public static function divide($leftOperand, $rightOperand) {
    $result = bcdiv($leftOperand, $rightOperand, self::CALCULATION_PRECISION_SCALE);
    return self::round($result);
  }

  public static function round($amount) {
    return round($amount, self::ROUNDING_DECIMAL_SCALE, PHP_ROUND_HALF_UP);
  }
}
