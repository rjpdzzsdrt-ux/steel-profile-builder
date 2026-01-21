# Steel Profile Builder

WordPressi plugin, mis võimaldab luua **administ muudetavaid plekiprofiile**  
(sirglõigud + sisemised nurgad + tagasipöörded), koos:

- 📐 SVG visuaalse joonisega
- ↔️ päris mõõtjoontega (nooled, paralleelsed dimension lines)
- 💰 pindala-põhise hinnakalkulatsiooniga (m²)
- 🧾 WPForms hidden field automaatse täitmisega

Plugin on mõeldud eelkõige plekitööde ja profiilide  
(nt harjaplekid, sokliplekid, eriprofiilid) hinnastamiseks ja päringute kogumiseks.

---

## Põhifunktsioonid

- Profiilid on **adminis hallatavad** (eraldi “Steel Profiilid” menüü)
- Mõõtude arv ei ole piiratud (s1, s2, a1, a2, s5 jne)
- Toetab **sisemisi nurki** + **L/R suunda** (tagasipöörded)
- SVG joonis skaleerub automaatselt
- Päris mõõtjooned koos noolte ja pööratud tekstiga
- Pindala-põhine hinnastus (m²)
- Automaatne sidumine WPForms vormiga

---

## Kasutusloogika (lühidalt)

1. Loo profiil WordPressi adminis
2. Lisa mõõdud (pikkused `s*` ja nurgad `a*`)
3. Määra pattern (järjestus)
4. Määra materjalide m² hinnad
5. Seo WPForms hidden fieldidega
6. Kasuta lehel shortcode’i

---

## Shortcode

```text
[steel_profile_builder id="123"]
