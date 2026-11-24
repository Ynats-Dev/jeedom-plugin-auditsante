---
layout: default
lang: es_ES
---

# Changelog Audit Santé

# 24/11/2026 - 0.1.0

- Primera versión: 42 comprobaciones en 7 categorías (referencias rotas, escenarios, dispositivos, comandos e historial, objetos/vistas/diseños, plugins y tareas, base de datos y archivos).
- Índice «quién usa qué» creado en una sola lectura de la base de datos.
- Enlaces a la página de Jeedom de cada elemento señalado.
- Archivo de nombres: registro cada hora del nombre de cada elemento, lectura única de las copias de seguridad (y de carpetas adicionales) para las eliminaciones más antiguas, período de eliminación. Lectura en segundo plano con seguimiento del avance, iniciada en la instalación, antes de la primera auditoría (que inicia el usuario).
- Correcciones automáticas limitadas a los elementos que ya nada puede usar.
- Datos del plugin en archivos JSON (`data/`), independientes de la base de datos de Jeedom.
- Puntuación, letra, comprobaciones ignoradas durante un tiempo, comentarios, informe de diagnóstico, dispositivo utilizable en los escenarios.
- Interfaz y documentación ilustrada en francés, inglés, alemán, español e italiano.
