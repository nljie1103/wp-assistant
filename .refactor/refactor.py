#!/usr/bin/env python3
from pathlib import Path
import base64
import json
import re
import shutil
import subprocess

ROOT = Path('.')
PAYLOADS = json.loads('{"jiuliu-wp-assistant.php": "PD9waHAKLyoqCiAqIFBsdWdpbiBOYW1lOiDkuZ3mtYFQVOWKqeaJiQogKiBQbHVnaW4gVVJJOiBodHRwczovL2dpdGh1Yi5jb20vbmxqaWUxMTAzL3dwLWFzc2lzdGFudAogKiBEZXNjcmlwdGlvbjog5LiA5Liq57uf5LiA44CB5a6M5pW055qEIFdvcmRQcmVzcyDlop7lvLrmj5Lku7bvvIzpm4bmiJDpobXpna/nuŽljJbjgIHms4nmspXlvI/pobXpna/jgIHlqpLkvZPkuI7lpJrln5/lkI3pk77mjqXnrqHnkIbogIXjgIFBSSDmlofnq6DmkZjopoHjgIIKICogVmVyc2lvbjogMi4wLjAKICogQXV0aG9yOiDkuZ3mtYEgKiBBdXRob3IgVVJJOiBodHRwczovL3d3dy5qaXVsaXUub3JnCiAqIExpY2Vuc2U6IEdQTHYyIG9yIGxhdGVyCiAqIExpY2Vuc2UgVVJJOiBodHRwczovL3d3dy5nbnUub3JnL2xpY2Vuc2VzL2dwbC0yLjAuaHRtbAogKiBUZXh0IERvbWFpbjogaml1bGl1LXdwLWFzc2lzdGFudAogKiBSZXF1aXJlcyBhdCBsZWFzdDogNS44CiAqIFJlcXVpcmVzIFBIUDogNy40CiAqLwoKaWYgKCAhIGRlZmluZWQoICdBQlNQQVRIJyApICkgewoJZXhpdDsKfQoKZGVmaW5lKCAnSkxXQV9WRVJTSU9OJywgJzIuMC4wJyApOwpkZWZpbmUoICdKTFdBX1BMVUdJTl9GSUxFJywgX19GSUxFX18gKTsKZGVmaW5lKCAnSkxXQV9QTFVHSU5fRElSJywgcGx1Z2luX2Rpcl9wYXRoKCBfX0ZJTEVfXyApICk7CmRlZmluZSggJ0pMV0FfUExVR0lOX1VSTCcsIHBsdWdpbl9kaXJfdXJsKCBfX0ZJTEVfXyApICk7CmRlZmluZSggJ0pMV0FfUExVR0lOX0JBU0VOQU1FJywgcGx1Z2luX2Jhc2VuYW1lKCBfX0ZJTEVfXyApICk7CmRlZmluZSggJ0pMV0FfTUVOVV9TTFVHJywgJ2ppdWxpdS13cC1hc3Npc3RhbnQnICk7CmRlZmluZSggJ0pMV0FfVU5JRklFRF9BRE1JTicsIHRydWUgKTsKCnJlcXVpcmVfb25jZSBKTFdBX1BMVUdJTl9ESVIgLiAnaW5jbHVkZXMvY2xhc3Mtamx3YS1mZWF0dXJlLXJlZ2lzdHJ5LnBocCc7CnJlcXVpcmVfb25jZSBKTFdBX1BMVUdJTl9ESVIgLiAnaW5jbHVkZXMvY2xhc3Mtamx3YS1hZG1pbi5waHAnOwpyZXF1aXJlX29uY2UgSkxXQV9QTFVHSU5fRElSIC4gJ2luY2x1ZGVzL2NsYXNzLWpsd2EtdXBkYXRlci5waHAnOwoKSkxXQV9GZWF0dXJlX1JlZ2lzdHJ5Ojpid290KCk7CkpMV0FfQWRtaW46Omluc3RhbmNlKCk7CgppZiAoIGlzX2FkbWluKCkgKSB7CglKTFdBX1VwZGF0ZXI6Omluc3RhbmNlKCk7Cn0KCnJlZ2lzdGVyX2FjdGl2YXRpb25faG9vayggSkxXQV9QTFVHSU5fRklMRSwgYXJyYXkoICdKTFdBX0ZlYXR1cmVfUmVnaXN0cnknLCAnYWN0aXZhdGUnICkgKTsK", "includes/class-jlwa-feature-registry.php": "PD9waHAKLyoqCiAqIEludGVybmFsIGZlYXR1cmUgcmVnaXN0cnkgZm9yIOS5nea1gVdQ5Yqp5omLLgogKgogKiBUaGUgcGx1Z2luIGhhcyBvbmUgbGlmZWN5Y2xlIGFuZCBvbmUgYWRtaW4gYXBwbGljYXRpb24uIEluZGl2aWR1YWwgZmVhdHVyZXMKICogb25seSBwcm92aWRlIGZvY3VzZWQgcnVudGltZSBzZXJ2aWNlcyBhbmQgc2V0dGluZ3Mgc2NyZWVucy4KICovCgppZiAoICEgZGVmaW5lZCggJ0FCU1BBVEgnICkgKSB7CglleGl0Owp9CgpjbGFzcyBKTFdBX0ZlYXR1cmVfUmVnaXN0cnkgewoJLyoqIEB2YXIgYXJyYXk8c3RyaW5nLGFycmF5PHN0cmluZyxtaXhlZD4+ICovCglwcm90ZWN0ZWQgc3RhdGljICRzdGF0dXNlcyA9IGFycmF5KCk7CgoJLyoqIEB2YXIgYm9vbCAqLwoJcHJvdGVjdGVkIHN0YXRpYyAkYm9vdGVkID0gZmFsc2U7CgoJLyoqCgkgKiBGZWF0dXJlIGRlZmluaXRpb25zLgogKgogKiBAcmV0dXJuIGFycmF5PHN0cmluZyxhcnJheTxzdHJpbmcsbWl4ZWQ+PgogKi8KCXB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gZmVhdHVyZXMoKSB7CgkJcmV0dXJuIGFycmF5KAoJCQkncGFnZS1lZmZlY3RzJyA9PiBhcnJheSgKCQkJCSdsYWJlbCcgICAgICAgPT4gJ+mhtemdoue+juWMl+S4micsCgkJCQknc2hvcnRfbGFiZWwnID0+ICfpoIXpnaLnvo7ljJYnLAoJCQkJJ2ljb24nICAgICAgICA9PiAnZGFzaGljb25zLWFydCcsCgkJCQknc2x1ZycgICAgICAgID0+ICdqbHdhLXBhZ2UtZWZmZWN0cycsCgkJCQkndmVyc2lvbicgICAgID0+ICcxLjYuMCcsCgkJCQknZW50cnlfY2xhc3MnID0+ICdKTFdBX1BhZ2VfRWZmZWN0c19GZWF0dXJlJywKCQkJCSdib290c3RyYXAnICAgPT4gSkxXQV9QTFVHSU5fRElSIC4gJ2ZlYXR1cmVzL3BhZ2UtZWZmZWN0cy9ib290c3RyYXAucGhwJywKCQkJCSdkZXNjcmlwdGlvbicgPT4gJ+ahn+WKseOAgembquaKs+OAgeeykuWtkOOAgeeBr+eti+OAgeiDjOaZr+S5k+OAgeWPsOmUr+S8muS4juiHquWumuS5ieS7o+eggS4nLAoJCQkJJ2V5ZWJyb3cnICAgICA9PiAnVklTVUFMIEVYUEVSSUVOQ0UnLAoJCQkJJ3N0YW5kYWxvbmUnICA9PiBhcnJheSgKCQkJCQknd3AtcGFnZS1lZmZlY3RzL3dwLXBhZ2UtZWZmZWN0cy5waHAnLAoJCQkJCSd4aWFvamllLXBhZ2UtZWZmZWN0cy94aWFvamllLXBhZ2UtZWZmZWN0cy5waHAnLAoJCQkJKSwKCQkJKSwKCQkJJ2FpLXN1bW1hcnknID0+IGFycmF5KAoJCQkJJ2xhYmVsJyAgICAgICA9PiAnQUkg5paH56ug5pGY6KaBJywKCQkJCSdzaG9ydF9sYWJlbCcgPT4gJ0FJIOaRmOimgScsCgkJCQknaWNvbicgICAgICAgID0+ICdkYXNoaWNvbnMtd2VsY29tZS13cml0ZS1ibG9nJywKCQkJCSdzbHVnJyAgICAgICAgPT4gJ3dwaWFzLXNldHRpbmdzJywKCQkJCSd2ZXJzaW9uJyAgICAgPT4gJzEuMC45JywKCQkJCSdlbnRyeV9jbGFzcycgPT4gJ0pMV0FfQUlfU3VtbWFyeV9GZWF0dXJlJywKCQkJCSdib290c3RyYXAnICAgPT4gSkxXQV9QTFVHSU5fRElSIC4gJ2ZlYXR1cmVzL2FpLWFydGljbGUtc3VtbWFyeS9ib290c3RyYXAucGhwJywKCQkJCSdkZXNjcmlwdGlvbicgPT4gJ+WkmuacjeWKoeWVhuaoo+Wei+OAgeaRmOimgeeUn+aIkOOAgee8k+WtmOOAgeWKqOeUu+S4juaWh+eroOe8lui+keWZqOW/q+mAn+euoeeQhuOAgicsCgkJCQknZXllYnJvdycgICAgID0+ICdDT05URU5UIElOVEVMTElHRU5DRScsCgkJCQknc3RhbmRhbG9uZScgID0+IGFycmF5KAoJCQkJCSdXUC1BSS1BcnRpY2xlLVN1bW1hcnkvd3AtYWktYXJ0aWNsZS1zdW1tYXJ5LnBocCcsCgkJCQkJJ3dwLWFpLWFydGljbGUtc3VtbWFyeS93cC1haS1hcnRpY2xlLXN1bW1hcnkucGhwJywKCQkJCSksCgkJCSksCgkJCSdwcmVsb2FkZXInID0+IGFycmF5KAoJCQkJJ2xhYmVsJyAgICAgICA9PiAn5rKJ5rW45byP6aKE5Yqg6L295LqLJywKCQkJCSdzaG9ydF9sYWJlbCcgPT4gJ+mihOWKoOi9vScsCgkJCQknaWNvbicgICAgICAgID0+ICdkYXNoaWNvbnMtaW1hZ2Utcm90YXRlJywKCQkJCSdzbHVnJyAgICAgICAgPT4gJ2psd2EtaW1tZXJzaXZlLXByZWxvYWRlcicsCgkJCQkndmVyc2lvbicgICAgID0+ICcxLjAuNicsCgkJCQknZW50cnlfY2xhc3MnID0+ICdKTFdBX0ltbWVyc2l2ZV9QcmVsb2FkZXJfRmVhdHVyZScsCgkJCQknYm9vdHN0cmFwJyAgID0+IEpMV0FfUExVR0lOX0RJUiAuICdmZWF0dXJlcy9pbW1lcnNpdmUtcHJlbG9hZGVyL2Jvb3RzdHJhcC5waHAnLAoJCQkJJ2Rlc2NyaXB0aW9uJyA9PiAn5aSa56eN5byA5bGP5pWI5p6c44CB6Ieq5a6a5LmJIExvZ2/kvJjlhZjliqDniLvlj7fnrYnku4XliLDlj6/ogIzpmaTjgIIJLAoJCQkJJ2V5ZWJyb3cnICAgICA9PiAnTE9BRElORyBFWFBFUklFTkNFJywKCQkJCSdzdGFuZGFsb25lJyAgPT4gYXJyYXkoCgkJCQkJJ3dwLWltbWVyc2l2ZS1wcmVsb2FkZXIvaml1bGl1LWltbWVyc2l2ZS1wcmVsb2FkZXIucGhwJywKCQkJCQknaml1bGl1LWltbWVyc2l2ZS1wcmVsb2FkZXIvaml1bGl1LWltbWVyc2l2ZS1wcmVsb2FkZXIucGhwJywKCQkJCSksCgkJCSksCgkJCSdtZWRpYS11cmxzJyA9PiBhcnJheSgKCQkJCSdsYWJlbCcgICAgICAgPT4gJ+WqkuS9k+S4jui/nuaOsScsCgkJCQknc2hvcnRfbGFiZWwnID0+ICflqpLkvZPkuI7pk77mjqUnLAoJCQkJJ2ljb24nICAgICAgICA9PiAnZGFzaGljb25zLWFkbWluLWxpbmtzJywKCQkJCSdzbHVnJyAgICAgICAgPT4gJ2psd2EtcmVsYXRpdmUtbWVkaWEtdXJscycsCgkJCQkndmVyc2lvbicgICAgID0+ICc0LjEuMScsCgkJCQknZW50cnlfY2xhc3MnID0+ICdKTFdBX01lZGlhX1VybHNfRmVhdHVyZScsCgkJCQknYm9vdHN0cmFwJyAgID0+IEpMV0FfUExVR0lOX0RJUiAuICdmZWF0dXJlcy9yZWxhdGl2ZS1tZWRpYS11cmxzL2Jvb3RzdHJhcC5waHAnLAoJCQkJJ2Rlc2NyaXB0aW9uJyA9PiAn55u45a+55aqS5L2T5Zyw5Z2A44CB5aSa5Z+f5ZCN6K6/6Zeu44CB5Y+N5ZCR5Luj55CG6K+K5pat44CB5omr5o+P6aKE6KeI5LiO5a6J5YWo5L+u5aSN44CCJywKCQkJCSdleWVicm93JyAgICAgPT4gJ0RFTElWRVJZICYgUk9VVElORycsCgkJCQknc3RhbmRhbG9uZScgID0+IGFycmF5KAoJCQkJCSd3cC1yZWxhdGl2ZS1tZWRpYS11cmxzL2ppdWxpdS1yZWxhdGl2ZS1tZWRpYS11cmxzLnBocCcsCgkJCQkJJ2ppdWxpdS1yZWxhdGl2ZS1tZWRpYS11cmxzL2ppdWxpdS1yZWxhdGl2ZS1tZWRpYS11cmxzLnBocCcsCgkJCQkpLAoJCQkpLAoJCSk7Cgl9CgoJLyoqIEJvb3QgYWxsIGludGVybmFsIGZlYXR1cmVzLiAqLwoJcHVibGljIHN0YXRpYyBmdW5jdGlvbiBib290KCkgewoJCWlmICggc2VsZjo6JGJvb3RlZCApIHsKCQkJcmV0dXJuOwoJCX0KCQlzZWxmOjokYm9vdGVkID0gdHJ1ZTsKCgkJZm9yZWFjaCAoIHNlbGY6OmZlYXR1cmVzKCkgYXMgJGtleSA9PiAkZmVhdHVyZSApIHsKCQkJc2VsZjo6JHN0YXR1c2VzWyAka2V5IF0gPSBzZWxmOjpid290X2ZlYXR1cmUoICRmZWF0dXJlICk7CgkJfQoJfQoKCS8qKgogKiBBY3RpdmF0ZSBkZWZhdWx0cyBmb3IgZXZlcnkgYXZhaWxhYmxlIGZlYXR1cmUuCiAqLwoJcHVibGljIHN0YXRpYyBmdW5jdGlvbiBhY3RpdmF0ZSgpIHsKCQlzZWxmOjpiZW90KCk7CgkJaWYgKCAhIGVtcHR5KCBzZWxmOjokc3RhdHVzZXNbJ3BhZ2UtZWZmZWN0cyddWydsb2FkZWQnXSApICYmIGNsYXNzX2V4aXN0cyggJ0pMV0FfUGFnZV9FZmZlY3RzX0ZlYXR1cmUnICkgKSB7CgkJCUpMV0FfUGFnZV9FZmZlY3RzX0ZlYXR1cmU6OmFjdGl2YXRlKCk7CgkJfQoKCQlpZiAoICEgZW1wdHkoIHNlbGY6OiRzdGF0dXNlc1snYWktc3VtbWFyeSddWydsb2FkZWQnXSApICYmIGNsYXNzX2V4aXN0cyggJ0pMV0FfQUlfU3VtbWFyeV9GZWF0dXJlJyApICkgewoJCQkkY3VycmVudCAgPSBnZXRfb3B0aW9uKCBXUEFJQVNfT1BUSU9OX0tFWSwgYXJyYXkoKSApOwoJCQkkY3VycmVudCAgPSBpc19hcnJheSggJGN1cnJlbnQgKSA/ICRjdXJyZW50IDogYXJyYXkoKTsKCQkJJGRlZmF1bHRzID0gSkxXQV9BSV9TdW1tYXJ5X0ZlYXR1cmU6OmdldF9kZWZhdWx0X3NldHRpbmdzKCk7CgkJCSRtZXJnZWQgICA9IHdwX3BhcnNlX2FyZ3MoICRjdXJyZW50LCAkZGVmYXVsdHMgKTsKCQkJdW5zZXQoICRtZXJnZWRbJ2FwaV9rZXknXSApOwoJCQl1cGRhdGVfb3B0aW9uKCBXUEFJQVNfT1BUSU9OX0tFWSwgJG1lcmdlZCApOwoJCX0KCgkJaWYgKCAhIGVtcHR5KCBzZWxmOjokc3RhdHVzZXNbJ3ByZWxvYWRlciddWydsb2FkZWQnXSApICYmIGNsYXNzX2V4aXN0cyggJ0pMV0FfSW1tZXJzaXZlX1ByZWxvYWRlcl9GZWF0dXJlJyApICkgewoJCQlKTFdBX0ltbWVyc2l2ZV9QcmVsb2FkZXJfRmVhdHVyZTo6aW5zdGFuY2UoKS0+b25fYWN0aXZhdGUoKTsKCQl9CgkJaWYgKCAhIGVtcHR5KCBzZWxmOjokc3RhdHVzZXNbJ21lZGlhLXVybHMnXVsnbG9hZGVkJ10gKSAmJiBjbGFzc19leGlzdHMoICdKTFdBX01lZGlhX1VybHNfRmVhdHVyZScgKSApIHsKCQkJSkxXQV9NZWRpYV9VcmxzX0ZlYXR1cmU6Omluc3RhbmNlKCktPm9uX2FjdGl2YXRlKCk7CgkJfQoKCQl1cGRhdGVfb3B0aW9uKCAnamx3YV9zY2hlbWFfdmVyc2lvbicsIEpMV0FfVkVSU0lPTiwgZmFsc2UgKTsKCX0KCgkvKiogQHJldHVybiBhcnJheTxzdHJpbmcsYXJyYXk8c3RyaW5nLG1peGVkPj4gKi8KCXB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gc3RhdHVzZXMoKSB7CgkJcmV0dXJuIHNlbGY6OiRzdGF0dXNlczsKCX0KCgkvKioKCSAqIFJldHVybiBhIGZlYXR1cmUgZGVmaW5pdGlvbi4KCSAqCiAqIEBwYXJhbSBzdHJpbmcgJGtleSBGZWF0dXJlIGtleS4KCSAqIEByZXR1cm4gYXJyYXk8c3RyaW5nLG1peGVkPnxudWxsCgkgKi8KCXB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gZ2V0KCAka2V5ICkgewoJCSRmZWF0dXJlcyA9IHNlbGY6OmZlYXR1cmVzKCk7CgkJcmV0dXJuIGlzc2V0KCAkZmVhdHVyZXNbICRrZXkgXSApID8gJGZlYXR1cmVzWyAka2V5IF0gOiBudWxsOwoJfQoKCS8qKgogKiBGaW5kIGEgZmVhdHVyZSBieSBpdHMgYWRtaW4gcGFnZSBzbHVnLgogKgogKiBAcGFyYW0gc3RyaW5nICRzbHVnIFBhZ2Ugc2x1Zy4KICogQHJldHVybiBzdHJpbmcKICovCglwdWJsaWMgc3RhdGljIGZ1bmN0aW9uIGtleV9mcm9tX3NsdWcoICRzbHVnICkgewoJCWZvcmVhY2ggKCBzZWxmOjpmZWF0dXJlcygpIGFzICRrZXkgPT4gJGZlYXR1cmUgKSB7CgkJCWlmICggJGZlYXR1cmVbJ3NsdWcnXSA9PT0gJHNsdWcgKSB7CgkJCQlyZXR1cm4gJGtleTsKCQkJfQoJCX0KCQlyZXR1cm4gJyc7Cgl9CgoJLyoqCgkgKiBSdW50aW1lIGZlYXR1cmUgdmVyc2lvbi4KICoKICogQHBhcmFtIHN0cmluZyAka2V5IEZlYXR1cmUga2V5LgogKiBAcmV0dXJuIHN0cmluZwogKi8KCXB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gdmVyc2lvbiggJGtleSApIHsKCQkkZmVhdHVyZSA9IHNlbGY6OmdldCggJGtleSApOwoJCWlmICggISAkZmVhdHVyZSApIHsKCQkJcmV0dXJuICctJzsKCQl9CgkJc3dpdGNoICggJGtleSApIHsKCQkJY2FzZSAncGFnZS1lZmZlY3RzJzoKCQkJCXJldHVybiBjbGFzc19leGlzdHMoICdKTFdBX1BhZ2VfRWZmZWN0c19GZWF0dXJlJywgZmFsc2UgKSA/ICggc3RyaW5nICkgSkxXQV9QYWdlX0VmZmVjdHNfRmVhdHVyZTo6VkVSU0lPTiA6ICRmZWF0dXJlWyd2ZXJzaW9uJ107CgkJCWNhc2UgJ2FpLXN1bW1hcnknOgoJCQkJcmV0dXJuIGRlZmluZWQoICdXUEFJQVNfVkVSU0lPTicgKSA/ICggc3RyaW5nICkgV1BBSUFTX1ZFUlNJT04gOiAkZmVhdHVyZVsndmVyc2lvbiddOwoJCQljYXNlICdwcmVsb2FkZXInOgoJCQkJcmV0dXJuIGRlZmluZWQoICdKSVBfVkVSU0lPTicgKSA/ICggc3RyaW5nICkgSklQX1ZFUlNJT04gOiAkZmVhdHVyZVsndmVyc2lvbiddOwoJCQljYXNlICdtZWRpYS11cmxzJzoKCQkJCXJldHVybiBkZWZpbmVkKCAnSlJNVV9WRVJTSU9OJyApID8gKCBzdHJpbmcgKSBKUk1VX1ZFUlNJT04gOiAkZmVhdHVyZVsndmVyc2lvbiddOwoJCQlkZWZhdWx0OgoJCQkJcmV0dXJuICRmZWF0dXJlWyd2ZXJzaW9uJ107CgkJfQoJfQoKCS8qKgogKiBSZW5kZXIgdGhlIHNlbGVjdGVkIGZlYXR1cmUncyBleGlzdGluZyBzZXR0aW5ncyBjb250cm9sbGVyIGluc2lkZSB0aGUKICogdW5pZmllZCBhZG1pbiBhcHBsaWNhdGlvbi4KICoKICogQHBhcmFtIHN0cmluZyAka2V5IEZlYXR1cmUga2V5LgogKi8KCXB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gcmVuZGVyX2FkbWluKCAka2V5ICkgewoJCXN3aXRjaCAoICRrZXkgKSB7CgkJCWNhc2UgJ3BhZ2UtZWZmZWN0cyc6CgkJCQlpZiAoIGNsYXNzX2V4aXN0cyggJ0pMV0FfUGFnZV9FZmZlY3RzX0ZlYXR1cmUnICkgKSB7CgkJCQkJSkxXQV9QYWdlX0VmZmVjdHNfRmVhdHVyZTo6aW5zdGFuY2UoKS0+cmVuZGVyX2FkbWluX3BhZ2UoKTsKCQkJCX0KCQkJCWJyZWFrOwoKCQkJY2FzZSAnYWktc3VtbWFyeSc6CgkJCQlpZiAoIGNsYXNzX2V4aXN0cyggJ0pMV0FfQUlfU3VtbWFyeV9GZWF0dXJlJyApICkgewoJCQkJCSRwbHVnaW4gPSBKTFdBX0FJX1N1bW1hcnlfRmVhdHVyZTo6aW5zdGFuY2UoKTsKCQkJCQlpZiAoIGlzc2V0KCAkcGx1Z2luLT5hZG1pbiApICYmIGlzX29iamVjdCggJHBsdWdpbi0+YWRtaW4gKSApIHsKCQkJCQkJJHBsdWdpbi0+YWRtaW4tPnJlbmRlcl9zZXR0aW5nc19wYWdlKCk7CgkJCQkJfQoJCQkJfQoJCQkJYnJlYWs7CgoJCQljYXNlICdwcmVsb2FkZXInOgoJCQkJaWYgKCBjbGFzc19leGlzdHMoICdKSVBfQWRtaW4nICkgKSB7CgkJCQkJSklQX0FkbWluOjppbnN0YW5jZSgpLT5yZW5kZXJfc2V0dGluZ3NfcGFnZSgpOwoJCQkJfQoJCQkJYnJlYWs7CgoJCQljYXNlICdtZWRpYS11cmxzJzoKCQkJCWlmICggY2xhc3NfZXhpc3RzKCAnSlJNVV9BZG1pbicgKSApIHsKCQkJCQlKUk1VX0FkbWluOjppbnN0YW5jZSgpLT5yZW5kZXJfcGFnZSgpOwoJCQkJfQoJCQkJYnJlYWs7CgkJfQoJfQoKCS8qKgogKiBIdW1hbi1yZWFkYWJsZSBmZWF0dXJlIHN0YXRlLgogKgogKiBAcGFyYW0gc3RyaW5nICRrZXkgRmVhdHVyZSBrZXkuCiAqIEByZXR1cm4gYXJyYXk8c3RyaW5nLHN0cmluZ3xib29sPgogKi8KCXB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gc3RhdGUoICRrZXkgKSB7CgkJJHN0YXR1cyA9IGlzc2V0KCBzZWxmOjokc3RhdHVzZXNbICRrZXkgXSApID8gc2VsZjo6JHN0YXR1c2VzWyAka2V5IF0gOiBhcnJheSgpOwoJCWlmICggZW1wdHkoICRzdGF0dXNbJ2xvYWRlZCddICkgKSB7CgkJCXJldHVybiBhcnJheSgKCQkJCSdyZWFkeScgPT4gZmFsc2UsCgkJCQknbGFiZWwnID0+ICfpgIDopoHlpITnkIYnLAoJCQkJJ3RvbmUnICA9PiAnZGFuZ2VyJywKCQkJKTsKCQl9CgkJJGVuYWJsZWQgPSBzZWxmOjppc19lbmFibGVkKCAka2V5ICk7CgkJcmV0dXJuIGFycmF5KAoJCQkncmVhZHknID0+IHRydWUsCgkJCSdsYWJlbCcgPT4gJGVuYWJsZWQgPyAn5bey5ZCv55SoJyA6ICflj6/nlKgnLAoJCQkndG9uZScgID0+ICRlbmFibGVkID8gJ3N1Y2Nlc3MnIDogJ25ldXRyYWwnLAoJCSk7Cgl9CgoJLyoqCgkgKiBCZXN0LWVmZm9ydCBlbmFibGVkIHN0YXRlIGZvciB0aGUgb3ZlcnZpZXcuCiAqCiAqIEBwYXJhbSBzdHJpbmcgJGtleSBGZWF0dXJlIGtleS4KICogQHJldHVybiBib29sCiAqLwoJcHJvdGVjdGVkIHN0YXRpYyBmdW5jdGlvbiBpc19lbmFibGVkKCAka2V5ICkgewoJCXN3aXRjaCAoICRrZXkgKSB7CgkJCWNhc2UgJ3BhZ2UtZWZmZWN0cyc6CgkJCQkkb3B0aW9ucyA9IGdldF9vcHRpb24oICd4anBlX29wdGlvbnMnLCBhcnJheSgpICk7CgkJCQlyZXR1cm4gISBlbXB0eSggJG9wdGlvbnNbJ2dsb2JhbCddWydlbmFibGVkJ10gKTsKCgkJCWNhc2UgJ2FpLXN1bW1hcnknOgoJCQkJJG9wdGlvbnMgPSBnZXRfb3B0aW9uKCAnd3BpYXNfc2V0dGluZ3MnLCBhcnJheSgpICk7CgkJCQlyZXR1cm4gISBlbXB0eSggJG9wdGlvbnNbJ2VuYWJsZWQnXSApOwoKCQkJY2FzZSAncHJlbG9hZGVyJzoKCQkJCSRvcHRpb25zID0gZ2V0X29wdGlvbiggJ2ppdWxpdV9pbW1lcnNpdmVfcHJlbG9hZGVyX29wdGlvbnMnLCBhcnJheSgpICk7CgkJCQlyZXR1cm4gISBlbXB0eSggJG9wdGlvbnNbJ2VuYWJsZWQnXSApOwoKCQkJY2FzZSAnbWVkaWEtdXJscyc6CgkJCQkkb3B0aW9ucyA9IGdldF9vcHRpb24oICdqaXVsaXVfcmVsYXRpdmVfbWVkaWFfdXJsc19vcHRpb25zJywgYXJyYXkoKSApOwoJCQkJZm9yZWFjaCAoIGFycmF5KAoJCQkJCSdjb252ZXJ0X2V4aXN0aW5nX21lZGlhX291dHB1dCcsCgkJCQkJJ2NvbnZlcnRfZnV0dXJlX21lZGlhX291dHB1dCcsCgkJCQkJJ2NvbnZlcnRfcG9zdF9vbl9zYXZlJywKCQkJCQknY29udmVydF9wb3N0X29uX2Zyb250ZW5kJywKCQkJCQknZG9tYWluX2FkYXB0YXRpb25fZW5hYmxlZCcsCgkJCQkJJ2Nhbm9uaWNhbF9lbmFibGVkJywKCQkJKSBhcyAkc2V0dGluZyApIHsKCQkJCQlpZiAoICEgZW1wdHkoICRvcHRpb25zWyAkc2V0dGluZyBdICkgKSB7CgkJCQkJCXJldHVybiB0cnVlOwoJCQkJCX0KCQkJCX0KCQkJCXJldHVybiBmYWxzZTsKCQl9CgkJcmV0dXJuIGZhbHNlOwoJfQoKCS8qKgogKiBMb2FkIG9uZSBpbnRlcm5hbCBmZWF0dXJlLgogKgogKiBAcGFyYW0gYXJyYXk8c3RyaW5nLG1peGVkPiAkZmVhdHVyZSBGZWF0dXJlIGRlZmluaXRpb24uCiAqIEByZXR1cm4gYXJyYXk8c3RyaW5nLG1peGVkPgogKi8KCXByb3RlY3RlZCBzdGF0aWMgZnVuY3Rpb24gYm9vdF9mZWF0dXJlKCAkZmVhdHVyZSApIHsKCQkkc3RhbmRhbG9uZSA9IHNlbGY6OmFjdGl2ZV9zdGFuZGFsb25lKCAkZmVhdHVyZSApOwoJCWlmICggJHN0YW5kYWxvbmUgKSB7CgkJCXJldHVybiBhcnJheSgKCQkJCSdsb2FkZWQnICAgICA9PiBmYWxzZSwKCQkJCSdzdGF0dXMnICAgICA9PiAnY29uZmxpY3QnLAoJCQkJJ21lc3NhZ2UnICAgID0+ICfml6fni6znq4vmj5Lku7bku43lnKjlkK/nlKjvvJrnmoTni6znq4vmj5Lku7bku43lnKjlkK/nlKjvvJrkupLkuIDplInogIzpmaTjgIIJIC4gJHN0YW5kYWxvbmUgLiAn44CC5Li65LqG6YG/5YWN6YeN5aSN6L6T5Ye677yM5pys5Yqf6IO95pqC5pyq5ZCv5Yqo44CCJywKCQkJCSdzdGFuZGFsb25lJyA9PiAkc3RhbmRhbG9uZSwKCQkJKTsKCQl9CgkJaWYgKCBlbXB0eSggJGZlYXR1cmVbJ2Jvb3RzdHJhcCddICkgfHwgISBpc19yZWFkYWJsZSggJGZlYXR1cmVbJ2Jvb3RzdHJhcCddICkgKSB7CgkJCXJldHVybiBhcnJheSgKCQkJCSdsb2FkZWQnICA9PiBmYWxzZSwKCQkJCSdzdGF0dXMnICA9PiAnbWlzc2luZycsCgkJCQknbWVzc2FnZScgPT4gJ+WGhemDqOWKn+iDveaWh+S7tuS4jeWtmOWcqOaIluS4jeWPr+ivu+OAgicsCgkJKTsKCQl9CgkJdHJ5IHsKCQkJcmVxdWlyZV9vbmNlICRmZWF0dXJlWydib290c3RyYXAnXTsKCQl9IGNhdGNoICggVGhyb3dhYmxlICRleGNlcHRpb24gKSB7CgkJCXJldHVybiBhcnJheSgKCQkJCSdsb2FkZWQnICA9PiBmYWxzZSwKCQkJCSdzdGF0dXMnICA9PiAnZXJyb3InLAoJCQkJJ21lc3NhZ2UnID0+ICfliqDmnI3lpLHotKU77oCZJyAuICRleGNlcHRpb24tPmdldE1lc3NhZ2UoKSwKCQkJKTsKCQl9CgkJaWYgKCBlbXB0eSggJGZlYXR1cmVbJ2VudHJ5X2NsYXNzJ10gKSB8fCAhIGNsYXNzX2V4aXN0cyggJGZlYXR1cmVbJ2VudHJ5X2NsYXNzJ10sIGZhbHNlICkgKSB7CgkJCXJldHVybiBhcnJheSgKCQkJCSdsb2FkZWQnICA9PiBmYWxzZSwKCQkJCSdzdGF0dXMnICA9PiAnaW52YWxpZCcsCgkJCQknbWVzc2FnZScgPT4gJ+WGhemDqOWKn+iDveWFpeWPo+acqueUqOWIsOato+ehrOazqOWGjOOAgicsCgkJKTsKCQl9CgkJcmV0dXJuIGFycmF5KAoJCQknbG9hZGVkJyAgPT4gdHJ1ZSwKCQkJJ3N0YXR1cycgID0+ICdyZWFkeScsCgkJCSdtZXNzYWdlJyA9PiAn5Yqf6IO95bey5q2j5bi45ZCv5Yqo44CCJywKCQkpOwoJfQoKCS8qKgogKiBSZXR1cm4gYW4gYWN0aXZlIGxlZ2FjeSBzdGFuZGFsb25lIGJhc2VuYW1lLCBvciBhbiBlbXB0eSBzdHJpbmcuCiAqCiAqIEBwYXJhbSBhcnJheTxzdHJpbmcsbWl4ZWQ+ICRmZWF0dXJlIEZlYXR1cmUgZGVmaW5pdGlvbi4KICogQHJldHVybiBzdHJpbmcKICovCglwcm90ZWN0ZWQgc3RhdGljIGZ1bmN0aW9uIGFjdGl2ZV9zdGFuZGFsb25lKCAkZmVhdHVyZSApIHsKCQkkYWN0aXZlID0gKCBhcnJheSApIGdldF9vcHRpb24oICdhY3RpdmVfcGx1Z2lucycsIGFycmF5KCkgKTsKCQlpZiAoIGlzX211bHRpc2l0ZSgpICkgewoJCQkkYWN0aXZlID0gYXJyYXlfbWVyZ2UoICRhY3RpdmUsIGFycmF5X2tleXMoICggYXJyYXkgKSBnZXRfc2l0ZV9vcHRpb24oICdhY3RpdmVfc2l0ZXdpZGVfcGx1Z2lucycsIGFycmF5KCkgKSApICk7CgkJfQoJCWZvcmVhY2ggKCAoIGFycmF5ICkgJGZlYXR1cmVbJ3N0YW5kYWxvbmUnXSBhcyAkYmFzZW5hbWUgKSB7CgkJCWlmICggaW5fYXJyYXkoICRiYXNlbmFtZSwgJGFjdGl2ZSwgdHJ1ZSApICkgewoJCQkJcmV0dXJuICRiYXNlbmFtZTsKCQkJfQoJCX0KCQlyZXR1cm4gJyc7Cgl9Cn0K", "includes/class-jlwa-admin.php": "PD9waHAKLyoqCiAqIFVuaWZpZWQgYWRtaW4gYXBwbGljYXRpb24gZm9yIOS5nea1gVdQ5Yqp5omLLgogKi8KCmlmICggISBkZWZpbmVkKCAnQUJTUEFUSCcgKSApIHsKCWV4aXQ7Cn0KCmNsYXNzIEpMV0FfQWRtaW4gewoJLyoqIEB2YXIgSkxXQV9BZG1pbnxudWxsICovCglwcm90ZWN0ZWQgc3RhdGljICRpbnN0YW5jZSA9IG51bGw7CgoJLyoqIEByZXR1cm4gSkxXQV9BZG1pbiAqLwoJcHVibGljIHN0YXRpYyBmdW5jdGlvbiBpbnN0YW5jZSgpIHsKCQlpZiAoIG51bGwgPT09IHNlbGY6OiRpbnN0YW5jZSApIHsKCQkJc2VsZjo6JGluc3RhbmNlID0gbmV3IHNlbGYoKTsKCQl9CgkJcmV0dXJuIHNlbGY6OiRpbnN0YW5jZTsKCX0KCglwcml2YXRlIGZ1bmN0aW9uIF9fY29uc3RydWN0KCkgewoJCWFkZF9hY3Rpb24oICdhZG1pbl9tZW51JywgYXJyYXkoICR0aGlzLCAncmVnaXN0ZXJfbWVudXMnICksIDIwICk7CgkJYWRkX2FjdGlvbiggJ2FkbWluX2VucXVldWVfc2NyaXB0cycsIGFycmF5KCAkdGhpcywgJ2VucXVldWVfYXNzZXRzJyApLCAxMDAgKTsKCQlhZGRfYWN0aW9uKCAnYWRtaW5fbm90aWNlcycsIGFycmF5KCAkdGhpcywgJ3JlbmRlcl9jb25mbGljdF9ub3RpY2VzJyApICk7CgkJYWRkX2ZpbHRlciggJ3BsdWdpbl9hY3Rpb25fbGlua3NfJyAuIEpMV0FfUExVR0lOX0JBU0VOQU1FLCBhcnJheSggJHRoaXMsICdwbHVnaW5fYWN0aW9uX2xpbmtzJyApICk7CgkJYWRkX2ZpbHRlciggJ2FkbWluX2JvZHlfY2xhc3MnLCBhcnJheSggJHRoaXMsICdhZG1pbl9ib2R5X2NsYXNzJyApICk7Cgl9CgoJLyoqIFJlZ2lzdGVyIHRoZSBwbHVnaW4ncyBvbmx5IG1lbnUgdHJlZS4gKi8KCXB1YmxpYyBmdW5jdGlvbiByZWdpc3Rlcl9tZW51cygpIHsKCQlhZGRfbWVudV9wYWdlKAoJCQkn5Lmd5rWBV1DliqnmiaMnLAoJCQkn5Lmd5rWBV1DliqnmiaMnLAoJCQknbWFuYWdlX29wdGlvbnMnLAoJCQlKTFdBX01FTlVfU0xVRywKCQkJYXJyYXkoICR0aGlzLCAncmVuZGVyX2Rhc2hib2FyZCcgKSwKCQkJJ2Rhc2hpY29ucy1zdXBlcmhlcm8tYWx0JywKCQkJNTguOAoJCSk7CgkJYWRkX3N1Ym1lbnVfcGFnZSgKCQkJSkxXQV9NRU5VX1NMVUcsCgkJCSfliqnmiaLmgLvop4gnLAoJCQkn5Yqp5omL5oC76KeIJywKCQkJJ21hbmFnZV9vcHRpb25zJywKCQkJSkxXQV9NRU5VX1NMVUcsCgkJCWFycmF5KCAkdGhpcywgJ3JlbmRlcl9kYXNoYm9hcmQnICkKCQkpOwoJCWZvcmVhY2ggKCBKTFdBX0ZlYXR1cmVfUmVnaXN0cnk6OmZlYXR1cmVzKCkgYXMgJGtleSA9PiAkZmVhdHVyZSApIHsKCQkJYWRkX3N1Ym1lbnVfcGFnZSgKCQkJCUppV0FfTUVOVV9TTFVHLAoJCQkJJGZlYXR1cmVbJ2xhYmVsJ10sCgkJCQkkZmVhdHVyZVsnc2hvcnRfbGFiZWwnXSwKCQkJCSdtYW5hZ2Vfb3B0aW9ucycsCgkJCQkkZmVhdHVyZVsnc2x1ZyddLAoJCQkJJHRoaXMtPmZlYXR1cmVfY2FsbGJhY2soICRrZXkgKQoJCQkpOwoJCX0KCQlhZGRfc3VibWVudV9wYWdlKAoJCQlKTFdBX01FTlVfU0xVRywKCQkJJ+ezu+e7n+S4juaIkOaWsCcsCgkJCSfns7vnu5/kuI7mm7TmlrAnLAoJCQkndXBkYXRlX3BsdWdpbnMnLAoJCQknamx3YS11cGRhdGUtY2VudGVyJywKCQkJYXJyYXkoICR0aGlzLCAncmVuZGVyX3VwZGF0ZV9jZW50ZXInICkKCQkpOwoJfQoKCS8qKgogKiBSZXR1cm4gYSBjb25jcmV0ZSBjYWxsYmFjayBhY2NlcHRlZCBieSBXb3JkUHJlc3MuCiAqCiAqIEBwYXJhbSBzdHJpbmcgJGtleSBGZWF0dXJlIGtleS4KICogQHJldHVybiBhcnJheTxpbnQsbWl4ZWQ+CiAqLwoJcHJvdGVjdGVkIGZ1bmN0aW9uIGZlYXR1cmVfY2FsbGJhY2soICRrZXkgKSB7CgkJJG1hcCA9IGFycmF5KAoJCQkncGFnZS1lZmZlY3RzJyA9PiAncmVuZGVyX3BhZ2VfZWZmZWN0cycsCgkJCSdhaS1zdW1tYXJ5JyAgID0+ICdyZW5kZXJfYWlfc3VtbWFyeScsCgkJCSdwcmVsb2FkZXInICAgID0+ICdyZW5kZXJfcHJlbG9hZGVyJywKCQkJJ21lZGlhLXVybHMnICAgPT4gJ3JlbmRlcl9tZWRpYV91cmxzJywKCQkpOwoJCXJldHVybiBhcnJheSggJHRoaXMsIGlzc2V0KCAkbWFwWyAka2V5IF0gKSA/ICRtYXBbICRrZXkgXSA6ICdyZW5kZXJfZGFzaGJvYXJkJyApOwoJfQoKCS8qKiBAcGFyYW0gc3RyaW5nICRjbGFzc2VzIEV4aXN0aW5nIGNsYXNzZXMuIEByZXR1cm4gc3RyaW5nICovCglwdWJsaWMgZnVuY3Rpb24gYWRtaW5fYm9keV9jbGFzcyggJGNsYXNzZXMgKSB7CgkJJHBhZ2UgPSAkdGhpcy0+Y3VycmVudF9wYWdlKCk7CgkJaWYgKCAkdGhpcy0+aXNfcGx1Z2luX3BhZ2UoICRwYWdlICkgKSB7CgkJCSRjbGFzc2VzIC49ICcgamx3YS1hZG1pbi1zY3JlZW4nOwoJCX0KCQlyZXR1cm4gJGNsYXNzZXM7Cgl9CgoJLyoqIEBwYXJhbSBzdHJpbmcgJGhvb2sgQ3VycmVudCBhZG1pbiBob29rLiAqLwoJcHVibGljIGZ1bmN0aW9uIGVucXVldWVfYXNzZXRzKCAkaG9vayApIHsKCQkkcGFnZSA9ICR0aGlzLT5jdXJyZW50X3BhZ2UoKTsKCQlpZiAoICEgJHRoaXMtPmlzX3BsdWdpbl9wYWdlKCAkcGFnZSApICkgewoJCQlyZXR1cm47CgkJfQoJCXdwX2VucXVldWVfc3R5bGUoICdqbHdhLWFkbWluJywgSkxXQV9QTFVHSU5fVVJMIC4gJ2Fzc2V0cy9jc3MvYWRtaW4uY3NzJywgYXJyYXkoKSwgSkxXQV9WRVJTSU9OICk7CgkJd3BfZW5xdWV1ZV9zY3JpcHQoICdqbHdhLWFkbWluJywgSkxXQV9QTFVHSU5fVVJMIC4gJ2Fzc2V0cy9qcy9hZG1pbi5qcycsIGFycmF5KCksIEpMV0FfVkVSU0lPTiwgdHJ1ZSApOwoJCXdwX2xvY2FsaXplX3NjcmlwdCgKCQkJJ2psd2EtYWRtaW4nLAoJCQknSkxXQV9BRE1JTicsCgkJCWFycmF5KAoJCQkJJ2FqYXhVcmwnID0+IGFkbWluX3VybCggJ2FkbWluLWFqYXgucGhwJyApLAoJCQkJJ25vbmNlJyAgID0+IHdwX2NyZWF0ZV9ub25jZSggJ2psd2FfdXBkYXRlX25vbmNlJyApLAoJCQkJJ3BhZ2UnICAgID0+ICRwYWdlLAoJCQkpCgkJKTsKCX0KCgkvKiogQHBhcmFtIGFycmF5PGludCxzdHJpbmc+ICRsaW5rcyBQbHVnaW4gbGlua3MuIEByZXR1cm4gYXJyYXk8aW50LHN0cmluZz4gKi8KCXB1YmxpYyBmdW5jdGlvbiBwbHVnaW5fYWN0aW9uX2xpbmtzKCAkbGlua3MgKSB7CgkJYXJyYXlfdW5zaGlmdCgKCQkJJGxpbmtzLAoJCQknPGEgaHJlZj0iJyAuIGVzY191cmwoIGFkbWluX3VybCggJ2FkbWluLnBocD9wYWdlPScgLiBKTFdBX01FTlVfU0xVRyApICkgLiAnIj7miZPlvIDmjqfliLbli7U8L2E+JwoJCSk7CgkJcmV0dXJuICRsaW5rczsKCX0KCgkvKiogRGlzcGxheSBhIHNpbmdsZSBjb2hlcmVudCBub3RpY2UgZm9yIGxlZ2FjeS1wbHVnaW4gY29uZmxpY3RzLiAqLwoJcHVibGljIGZ1bmN0aW9uIHJlbmRlcl9jb25mbGljdF9ub3RpY2VzKCkgewoJCWlmICggISBjdXJyZW50X3VzZXJfY2FuKCAnbWFuYWdlX29wdGlvbnMnICkgKSB7CgkJCXJldHVybjsKCQl9CgkJJG1lc3NhZ2VzID0gYXJyYXkoKTsKCQlmb3JlYWNoICggSkxXQV9GZWF0dXJlX1JlZ2lzdHJ5OjpzdGF0dXNlcygpIGFzICRrZXkgPT4gJHN0YXR1cyApIHsKCQkJaWYgKCAhIGVtcHR5KCAkc3RhdHVzWydsb2FkZWQnXSApIHx8IGVtcHR5KCAkc3RhdHVzWydtZXNzYWdlJ10gKSApIHsKCQkJCWNvbnRpbnVlOwoJCQl9CgkJCSRmZWF0dXJlICAgID0gSkxXQV9GZWF0dXJlX1JlZ2lzdHJ5OjpnZXQoICRrZXkgKTsKCQkJJG1lc3NhZ2VzW10gPSAoICRmZWF0dXJlID8gJGZlYXR1cmVbJ2xhYmVsJ10gOiAka2V5ICkgLiAn77yaJyAuICRzdGF0dXNbJ21lc3NhZ2UnXTsKCQl9CgkJaWYgKCAhICRtZXNzYWdlcyApIHsKCQkJcmV0dXJuOwoJCX0KCQllY2hvICc8ZGl2IGNsYXNzPSJub3RpY2Ugbm90aWNlLXdhcm5pbmciPjxwPjxzdHJvbmc+5Lmd5rWBV1DliqnmiaLmo4DmtYvliLDlpobnlq/77ya8L3N0cm9uZz4nIC4gZXNjX2h0bWwoIGltcGxvZGUoICfvvJsnLCAkbWVzc2FnZXMgKSApIC4gJyA8YSBocmVmPSInIC4gZXNjX3VybCggYWRtaW5fdXJsKCAncGx1Z2lucy5waHAnICkgKSAuICciPuaJk+W8gOaPkuS7tuWIl+ihqDwvYT48L3A+PC9kaXY+JzsKCX0KCglwdWJsaWMgZnVuY3Rpb24gcmVuZGVyX2Rhc2hib2FyZCgpIHsKCQlpZiAoICEgY3VycmVudF91c2VyX2NhbiggJ21hbmFnZV9vcHRpb25zJyApICkgewoJCQlyZXR1cm47CgkJfQoJCSRmZWF0dXJlcyA9IEpMV0FfRmVhdHVyZV9SZWdpc3RyeTo6ZmVhdHVyZXMoKTsKCQkkc3RhdHVzZXMgPSBKTFdBX0ZlYXR1cmVfUmVnaXN0cnk6OnN0YXR1c2VzKCk7CgkJJHJlYWR5ICAgID0gMDsKCQlmb3JlYWNoICggJHN0YXR1c2VzIGFzICRzdGF0dXMgKSB7CgkJCWlmICggISBlbXB0eSggJHN0YXR1c1snbG9hZGVkJ10gKSApIHsKCQkJCSsrJHJlYWR5OwoJCQl9CgkJfQoJCQkkdGhpcy0+c2hlbGxfc3RhcnQoICdkYXNoYm9hcmQnLCAn57uf5LiA5o6n5Yi25Y+wJywgJ+S4gOS4quaPkuS7tueuooeeQhuWbm+mht+a0u+W/g+WKn+iDve+8jOiuvue9ruOAgeeVjOmdouS4juaIkOaWsOWFqOmDqOmbhuS4reOAgicgKTsKCQk/PgoJCTxzZWN0aW9uIGNsYXNzPSJqbHdhLWhlcm8iPgoJCQk8ZGl2IGNsYXNzPSJqbHdhLWhlcm9fX2NvcHkiPgoJCQkJPHNwYW4gY2xhc3M9Impsd2Eta2lja2VyIj5KSVVMSVUgV09SRFBSRVNTIEFTU0lTVEFOVCAyLjA8L3NwYW4+CgkJCQk8aDI+5LiN5piv5Zub5Liq5o+S5Lu255qE6ZuG5ZCI77yMPGJyPuW而是一套完整的网站增强系统。</h2>
				<p>页面体验、加载体验、内容智能和媒体链接共用同一个插件生命周期、同一个后台应用与同一个更新源；内部按功能域拆分，仅用于保证代码清晰和维护安全。</p>
				<div class="jlwa-hero__actions">
					<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=jlwa-page-effects' ) ); ?>">开始配置</a>
					<a class="button button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=jlwa-update-center' ) ); ?>">系统与更新</a>
				</div>
			</div>
			<div class="jlwa-hero__stats">
				<div><strong><?php echo esc_html( $ready ); ?>/<?php echo esc_html( count( $features ) ); ?></strong><span>功能就绪</span></div>
				<div><strong>1</strong><span>插件入口</span></div>
				<div><strong>v<?php echo esc_html( JLWA_VERSION ); ?></strong><span>当前版本</span></div>
			</div>
		</section>

		<div class="jlwa-section-title">
			<div><span>CORE FEATURES</span><h2>四项能力，一套体验</h2></div>
			<p>每个功能都有独立职责，但不再拥有独立插件头、激活流程、菜单树或更新器。</p>
		</div>

		<div class="jlwa-feature-grid">
			<?php foreach ( $features as $key => $feature ) : ?>
				<?php
				$state  = JLWA_Feature_Registry::state( $key );
				$status = isset( $statuses[ $key ] ) ? $statuses[ $key ] : array();
				?>
				<article class="jlwa-feature-card jlwa-feature-card--<?php echo esc_attr( $key ); ?>">
					<div class="jlwa-feature-card__top">
						<span class="jlwa-feature-icon"><span class="dashicons <?php echo esc_attr( $feature['icon'] ); ?>"></span></span>
						<span class="jlwa-state-badge is-<?php echo esc_attr( $state['tone'] ); ?>"><?php echo esc_html( $state['label'] ); ?></span>
					</div>
					<span class="jlwa-feature-eyebrow"><?php echo esc_html( $feature['eyebrow'] ); ?></span>
					<h3><?php echo esc_html( $feature['label'] ); ?></h3>
					<p><?php echo esc_html( $feature['description'] ); ?></p>
					<div class="jlwa-feature-card__footer">
						<span>内部功能版本 v<?php echo esc_html( JLWA_Feature_Registry::version( $key ) ); ?></span>
						<?php if ( ! empty( $status['loaded'] ) ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $feature['slug'] ) ); ?>">管理功能</a>
						<?php else : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">处理冲突</a>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>

		<section class="jlwa-architecture-note">
			<div class="jlwa-architecture-note__icon"><span class="dashicons dashicons-admin-generic"></span></div>
			<div>
				<span>UNIFIED ARCHITECTURE</span>
				<h2>统一生命周期，功能域内部解耦</h2>
				<p>WordPress 只识别一个“九流WP助手”插件。四项功能由中央注册表启动，后台菜单由统一控制台注册，在线更新只覆盖一个完整发布包。</p>
			</div>
			<div class="jlwa-checklist">
				<span><i></i>一个插件头</span>
				<span><i></i>一个激活入口</span>
				<span><i></i>一个菜单中心</span>
				<span><i></i>一个安全更新器</span>
			</div>
		</section>
		<?php
		$this->shell_end();
	}

	public function render_page_effects() {
		$this->render_feature( 'page-effects' );
	}

	public function render_ai_summary() {
		$this->render_feature( 'ai-summary' );
	}

	public function render_preloader() {
		$this->render_feature( 'preloader' );
	}

	public function render_media_urls() {
		$this->render_feature( 'media-urls' );
	}

	/**
	 * Render one feature inside the unified application shell.
	 *
	 * @param string $key Feature key.
	 */
	protected function render_feature( $key ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$feature = JLWA_Feature_Registry::get( $key );
		$all_statuses = JLWA_Feature_Registry::statuses();
		$status  = isset( $all_statuses[ $key ] ) ? $all_statuses[ $key ] : array();
		if ( ! $feature ) {
			$this->render_dashboard();
			return;
		}
		$this->shell_start( $key, $feature['label'], $feature['description'] );
		if ( empty( $status['loaded'] ) ) {
			echo '<section class="jlwa-empty-state"><span class="dashicons dashicons-warning"></span><h2>该功能暂未启动</h2><p>' . esc_html( isset( $status['message'] ) ? $status['message'] : '状态未知。' ) . '</p><a class="button button-primary" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">检查旧插件冲突</a></section>';
		} else {
			echo '<div class="jlwa-feature-host jlwa-feature-host--' . esc_attr( $key ) . '">';
			JLWA_Feature_Registry::render_admin( $key );
			echo '</div>';
		}
		$this->shell_end();
	}

	public function render_update_center() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$this->shell_start( 'system', '系统与更新', '检查完整性、查看功能版本，并从唯一主仓库安全更新。' );
		?>
		<div class="jlwa-system-grid">
			<section class="jlwa-update-panel">
				<div class="jlwa-update-panel__head">
					<div>
						<span class="jlwa-kicker">SECURE UPDATE</span>
						<h2>九流WP助手 v<?php echo esc_html( JLWA_VERSION ); ?></h2>
						<p>只从 <code>nljie1103/wp-assistant</code> 获取完整插件包，不再访问任何旧独立仓库。</p>
					</div>
					<div class="jlwa-version-orb">v<?php echo esc_html( JLWA_VERSION ); ?></div>
				</div>
				<div class="jlwa-update-protection">
					<span><i class="dashicons dashicons-yes-alt"></i>版本校验</span>
					<span><i class="dashicons dashicons-yes-alt"></i>SHA-256 校验</span>
					<span><i class="dashicons dashicons-yes-alt"></i>完整备份</span>
					<span><i class="dashicons dashicons-yes-alt"></i>失败回滚</span>
				</div>
				<div class="jlwa-update-actions">
					<button type="button" class="button button-secondary" id="jlwa-check-update">立即检查更新</button>
					<button type="button" class="button button-primary" id="jlwa-do-update" disabled>安全更新插件</button>
				</div>
				<div id="jlwa-update-status" class="jlwa-update-status">尚未检查远程版本。</div>
				<pre id="jlwa-update-log" class="jlwa-update-log">（检查后显示版本说明）</pre>
			</section>
			<aside class="jlwa-system-side">
				<h2>功能版本</h2>
				<?php foreach ( JLWA_Feature_Registry::features() as $key => $feature ) : ?>
					<div class="jlwa-system-version">
						<span class="dashicons <?php echo esc_attr( $feature['icon'] ); ?>"></span>
						<div><strong><?php echo esc_html( $feature['label'] ); ?></strong><span>v<?php echo esc_html( JLWA_Feature_Registry::version( $key ) ); ?></span></div>
					</div>
				<?php endforeach; ?>
				<div class="jlwa-system-tip"><strong>数据策略</strong><p>更新插件不会删除现有设置、AI 缓存、媒体扫描记录或文章数据。</p></div>
			</aside>
		</div>
		<?php
		$this->shell_end();
	}

	protected function shell_start( $active, $title, $subtitle ) {
		?>
		<div class="wrap jlwa-app jlwa-app--<?php echo esc_attr( $active ); ?>">
			<header class="jlwa-appbar">
				<a class="jlwa-brand" href="<?php echo esc_url( admin_url( 'admin.php?page=' . JLWA_MENU_SLUG ) ); ?>">
					<span class="jlwa-brand__mark"><span class="dashicons dashicons-superhero-alt"></span></span>
					<span><strong>九流WP助手</strong><small>Unified WordPress Toolkit</small></span>
				</a>
				<nav class="jlwa-appnav" aria-label="九流WP助手功能导航">
					<?php $this->render_nav_item( 'dashboard', JLWA_MENU_SLUG, '控制台', 'dashicons-dashboard', $active ); ?>
					<?php foreach ( JLWA_Feature_Registry::features() as $key => $feature ) : ?>
						<?php $this->render_nav_item( $key, $feature['slug'], $feature['short_label'], $feature['icon'], $active ); ?>
					<?php endforeach; ?>
					<?php $this->render_nav_item( 'system', 'jlwa-update-center', '系统', 'dashicons-update-alt', $active ); ?>
				</nav>
				<span class="jlwa-appbar__version">v<?php echo esc_html( JLWA_VERSION ); ?></span>
			</header>
			<div class="jlwa-page-heading"><div><span>JIULIU ASSISTANT</span><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $subtitle ); ?></p></div></div>
			<main class="jlwa-main">
		<?php
	}

	protected function shell_end() {
		echo '</main></div>';
	}

	protected function render_nav_item( $key, $slug, $label, $icon, $active ) {
		$class = 'jlwa-appnav__item' . ( $key === $active ? ' is-active' : '' );
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '"><span class="dashicons ' . esc_attr( $icon ) . '"></span><span>' . esc_html( $label ) . '</span></a>';
	}

	protected function current_page() {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	protected function is_plugin_page( $page ) {
		if ( in_array( $page, array( JLWA_MENU_SLUG, 'jlwa-update-center' ), true ) ) {
			return true;
		}
		return '' !== JLWA_Feature_Registry::key_from_slug( $page );
	}
}
", "assets/css/admin.css": "O...TRUNCATED...", "assets/js/admin.js": "K...TRUNCATED...", "readme.txt": "P...TRUNCATED...", "README.md": "I...TRUNCATED...", "features/ai-article-summary/bootstrap.php": "P...TRUNCATED...", "features/immersive-preloader/bootstrap.php": "P...TRUNCATED...", "features/relative-media-urls/bootstrap.php": "P...TRUNCATED..."}')

# The full payload is generated by the release workflow. This staging file is replaced in the next commit.
raise SystemExit('staging placeholder')
