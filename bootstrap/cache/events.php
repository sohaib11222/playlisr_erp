<?php return array (
  'App\\Providers\\EventServiceProvider' => 
  array (
    'App\\Events\\TransactionPaymentAdded' => 
    array (
      0 => 'App\\Listeners\\AddAccountTransaction',
    ),
    'App\\Events\\TransactionPaymentUpdated' => 
    array (
      0 => 'App\\Listeners\\UpdateAccountTransaction',
    ),
    'App\\Events\\TransactionPaymentDeleted' => 
    array (
      0 => 'App\\Listeners\\DeleteAccountTransaction',
    ),
  ),
);