<?php

return [
    'iban_format' => 'The :attribute must be a valid Ukrainian IBAN (UA followed by 27 digits).',
    'iban_checksum' => 'The :attribute has invalid IBAN check digits.',
    'iban_unknown_bank' => 'The :attribute belongs to an unknown bank (MFO :mfo).',
    'iban_bank_not_operating' => 'The :attribute belongs to :bank, which is not operating (status: :status).',
    'mfo_format' => 'The :attribute must be a 6-digit MFO bank code.',
    'mfo_unknown' => 'The :attribute is not a known bank MFO.',
    'mfo_bank_not_operating' => 'The :attribute belongs to :bank, which is not operating (status: :status).',
    'registry_missing' => 'The :attribute cannot be verified because the bank registry is not available.',
];
