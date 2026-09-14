<?php

return [
    'iban_format' => 'Поле :attribute має бути коректним українським IBAN (UA і 27 цифр).',
    'iban_checksum' => 'Поле :attribute містить IBAN з неправильними контрольними цифрами.',
    'iban_unknown_bank' => 'Поле :attribute містить IBAN невідомого банку (МФО :mfo).',
    'iban_bank_not_operating' => 'Поле :attribute містить IBAN банку :bank, який не працює (стан: :status).',
    'mfo_format' => 'Поле :attribute має бути МФО з 6 цифр.',
    'mfo_unknown' => 'Поле :attribute не є МФО відомого банку.',
    'mfo_bank_not_operating' => 'Поле :attribute належить банку :bank, який не працює (стан: :status).',
    'registry_missing' => 'Поле :attribute неможливо перевірити: довідник банків недоступний.',
];
