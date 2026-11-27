---
layout: default
lang: es_ES
---

# Changelog Audit Santé

# 26/11/2026 - 0.1.1

- Botón «Excluir» ampliado a los plugins desactivados, los equipos mudos, huérfanos o en timeout, los objetos vacíos y las vistas y diseños vacíos (sensor que cambia rara vez, aparato de temporada, equipo leído por la API, habitación preparada de antemano...).
- Escenarios y equipos desactivados a propósito: botón «Excluir» en seis comprobaciones (escenarios que nunca pueden lanzarse, que ya no se lanzan, que utilizan elementos desactivados, disparados por un equipo desactivado, escenarios desactivados olvidados, equipos desactivados). El elemento sigue apareciendo aparte, ya no cuenta en la nota y se reintegra con un clic.
- Equipos y comandos vinculados a elementos eliminados: ubicación de la referencia (acción antes o después de la ejecución, acción sobre valor, valor vinculado...), apertura directa de la configuración avanzada del comando y botón que quita la acción huérfana mediante Jeedom.
- Comando de información: una acción antes o después de la ejecución, que Jeedom no muestra en este tipo de comando, se indica como oculta, con el procedimiento (guardar el comando sin modificar nada, o el botón «Eliminar el valor info huérfano»).
- Equipos fantasma: corrección automática que elimina los equipos de los plugins que ya no están instalados, con sus comandos y su historial. Los de los plugins desactivados no se modifican.
- Escenarios que usan elementos eliminados: cada referencia indica su ubicación (disparador, condición o acción de un bloque, y «en sus opciones» si está en un campo de la acción).
- Los disparadores de un escenario en modo Programado, que Jeedom conserva en la base pero oculta y no usa, ya no se comprueban.
- Un número mayor que el último identificador asignado por Jeedom (identificador de conversación de Telegram, número de teléfono...) ya no se indica como referencia no encontrada.
- Restos de plugins: se muestran los nombres de los parámetros y se explican las configuraciones guardadas bajo una ruta de archivo o sin nombre de plugin («false», «null»).

# 24/11/2026 - 0.1.0

- Primera versión: 42 comprobaciones en 7 categorías (referencias rotas, escenarios, dispositivos, comandos e historial, objetos/vistas/diseños, plugins y tareas, base de datos y archivos).
- Índice «quién usa qué» creado en una sola lectura de la base de datos.
- Enlaces a la página de Jeedom de cada elemento señalado.
- Archivo de nombres: registro cada hora del nombre de cada elemento, lectura única de las copias de seguridad (y de carpetas adicionales) para las eliminaciones más antiguas, período de eliminación. Lectura en segundo plano con seguimiento del avance, iniciada en la instalación, antes de la primera auditoría (que inicia el usuario).
- Correcciones automáticas limitadas a los elementos que ya nada puede usar.
- Datos del plugin en archivos JSON (`data/`), independientes de la base de datos de Jeedom.
- Puntuación, letra, comprobaciones ignoradas durante un tiempo, comentarios, informe de diagnóstico, dispositivo utilizable en los escenarios.
- Interfaz y documentación ilustrada en francés, inglés, alemán, español e italiano.
- Envío de una copia de seguridad desde el ordenador (.tar.gz, .tgz, .tar o .zip), sin dar acceso a una carpeta.
