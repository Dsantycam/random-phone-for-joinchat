=== Random Phone for Joinchat ===
Contributors: Dsantycam
Tags: joinchat, whatsapp, random, phone, agents
Requires at least: 5.0.0
Tested up to: 6.9
Requires PHP: 7.0
Stable tag: 4.5.0
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

**Panel de reparto (Joinchat > Reparto de contactos):** muestra cómo se han ido
repartiendo los contactos: visitantes asignados y aperturas de WhatsApp por
teléfono y por día, la parte que recibe cada uno frente a la que le corresponde
por sus cupos, la ronda actual de la bolsa, desde dónde y con qué dispositivo
contactan, y las páginas con más contactos. Se puede exportar a CSV.

Solo guarda totales diarios por teléfono, nada de los visitantes (ni IP, ni
número, ni cookies), y borra solo lo que tenga más de 13 meses. Si se quiere
apagar: `add_filter( 'joinchat_random_phone_stats', '__return_false' );`.

== Installation ==

1. Instala y activa **Joinchat** (cualquier versión desde la 3.0 "WAme" hasta la 6.x). La página de permisos por rol necesita Joinchat 4.2 o superior.
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

= ¿El panel sabe si el visitante llegó a escribir? =
No. Sabe a qué teléfono se le asignó y si abrió WhatsApp, pero lo que pasa
dentro de WhatsApp (si envía el mensaje, su número) no llega a la web.

= ¿Y si un visitante borra los datos del navegador? =
Se le tratará como visitante nuevo y se le asignará el siguiente número de la
bolsa global.

== Changelog ==

Ver changelog.txt
