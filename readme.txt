=== Random Phone for Joinchat ===
Contributors: Dsantycam
Tags: joinchat, whatsapp, random, phone, agents
Requires at least: 5.0.0
Tested up to: 6.9
Requires PHP: 7.0
Stable tag: 4.0.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Reparte los contactos de Joinchat entre una lista de teléfonos/agentes, de forma
global y equitativa, con asignación fija por visitante.

== Description ==

Extensión para **Joinchat** (WordPress) que, al pulsar el botón de WhatsApp,
reparte los contactos entre varios números de teléfono / agentes.

**Cómo reparte los contactos:**

* **Global y equitativo (shuffle-bag en servidor):** todos los visitantes comparten
  una misma "bolsa" barajada de números. Cada visitante nuevo saca el siguiente
  número; ninguno se repite hasta agotar la lista. Así todos los agentes reciben
  aproximadamente la misma cantidad de contactos. Un número puede aparecer varias
  veces en la lista para darle más peso.
* **Fijo por visitante (sticky):** una vez que un visitante recibe un número, se
  guarda en su navegador y siempre volverá a caer en el mismo agente, aunque
  vuelva a pulsar el botón otro día.

Funciona correctamente aunque tengas caché de páginas, porque la asignación se
resuelve mediante una petición AJAX (no queda "congelada" en el HTML cacheado).

El número por defecto (`telephone`) se mantiene siempre como un único número
válido, usado por el QR, el servidor y como reserva.

== Installation ==

1. Instala y activa **Joinchat** (versión 6.0 o superior).
2. Sube e instala este plugin (`joinchat-random-phone`) y actívalo.
3. En **Joinchat > Ajustes**, en el campo Teléfono, pulsa el botón "+" para añadir
   todos los números entre los que quieras repartir los contactos.

== Auto-actualización ==

Las actualizaciones se descargan automáticamente desde GitHub. Edita en
`joinchat-random-phone.php` la constante `JOINCHAT_RANDOM_PHONE_GITHUB` con la URL
de tu repositorio. Para publicar una actualización, crea una "Release" en GitHub
con la nueva versión (la misma que en la cabecera del plugin y en `Stable tag`).

== Frequently Asked Questions ==

= ¿El reparto es exactamente 50/50? =
Es lo más parejo posible, pero nunca matemáticamente perfecto: hay visitantes que
abren el chat y no escriben, bots, cachés y gente que borra cookies. La bolsa
global se acerca todo lo posible a la equidad real.

= ¿Y si un visitante borra los datos del navegador? =
Se le tratará como visitante nuevo y se le asignará el siguiente número de la
bolsa global.

== Changelog ==

Ver changelog.txt
