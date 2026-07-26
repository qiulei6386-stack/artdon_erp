<?php
declare(strict_types=1);
return[
 'version'=>'20260726_011_field_validation_ranges',
 'description'=>'Restore strict electrical, optical and chip field validation ranges',
 'up'=>[
  "UPDATE mc_field_registry SET validation_json='{\"min\":0,\"max\":100}' WHERE field_code='chip.cri'",
  "UPDATE mc_field_registry SET validation_json='{\"min\":0,\"max\":180}' WHERE field_code IN('optical.beam_angle_min','optical.beam_angle_max')",
  "UPDATE mc_field_registry SET validation_json='{\"min\":0,\"max\":1}' WHERE field_code IN('power.power_factor','power.efficiency','optical.transmittance')",
  "UPDATE mc_field_registry SET validation_json='{\"min\":0,\"max\":20}' WHERE field_code='power.supplier_warranty_years'",
 ],
 'down'=>[
  "UPDATE mc_field_registry SET validation_json='{\"min\":0}' WHERE field_code IN('chip.cri','optical.beam_angle_min','optical.beam_angle_max','power.power_factor','power.efficiency','optical.transmittance','power.supplier_warranty_years')",
 ],
];
