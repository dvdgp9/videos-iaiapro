# Plantillas Hyperframes

Cada subcarpeta aquí es una plantilla disponible para el usuario cuando crea
un proyecto. Las plantillas son leídas por el frontend PHP (R.7) y el HTML
compilado se copia al directorio de cada proyecto bajo
`/home/dvdgp/data/videos/projects/<project_id>/`.

## Convención

```
<template_id>/
├── meta.json             # ficha para el frontend y el compilador
├── hyperframes.json      # config hyperframes que se copia a cada proyecto
├── index.html.tmpl       # HTML con placeholders {{variable}}
└── preview.png           # (opcional) captura estática para el catálogo
```

## `meta.json`

Describe la plantilla y los campos que el usuario rellena:

```jsonc
{
  "id": "basic-promo",                     // debe coincidir con el nombre de carpeta
  "name": "Promo básico",                  // mostrado en el catálogo
  "description": "...",                    // una línea
  "duration_seconds": 10,
  "formats": ["16:9", "9:16", "1:1"],
  "default_format": "16:9",
  "fields": [                              // rellenados por el usuario en el wizard
    { "key": "title", "type": "string", "label": "Título", "required": true, "max_length": 80 }
  ],
  "style_fields": [                        // colores / tipografía
    { "key": "primary_color", "type": "color", "default": "#1f2937" }
  ],
  "assets": [                              // uploads opcionales
    { "key": "logo", "role": "logo", "required": false, "accept": "image/*" }
  ]
}
```

Tipos soportados en `fields`:
- `string` (+`max_length`)
- `text` (multilínea, +`max_length`)
- `color` (`#RRGGBB`)
- `number` (+`min`, `max`)
- `select` (+`options: [{value, label}]`)

## `index.html.tmpl`

HTML de la composición Hyperframes con placeholders Mustache-lite.
**Sólo se soporta `{{variable}}`** — no hay condicionales ni bucles. Si un
campo opcional queda vacío, PHP sustituye por cadena vacía; el CSS debe
manejar esos casos (p.ej. `[data-field=""]{display:none}`).

Variables disponibles siempre:

| Placeholder          | Valor                                                          |
| -------------------- | -------------------------------------------------------------- |
| `{{width}}`          | p.ej. `1920` (según `format` del proyecto)                     |
| `{{height}}`         | p.ej. `1080`                                                   |
| `{{duration}}`       | duración en segundos (float)                                   |
| `{{project_id}}`     | ID del proyecto (útil para debugging)                          |
| `{{<campo>}}`        | cualquier clave definida en `fields` o `style_fields`, HTML-escaped |
| `{{asset_<key>}}`    | ruta relativa del asset subido (`./assets/logo.png`) o `""`    |

## Render

El compilador (PHP en R.7) crea en el directorio del proyecto:

```
<project_dir>/
├── hyperframes.json    (copiado tal cual)
├── index.html          (resultado de rellenar index.html.tmpl)
└── assets/             (uploads del usuario renombrados a canónico)
    ├── logo.png
    └── main.jpg
```

Después el worker Node ejecuta `hyperframes render <project_dir> --output <mp4>`.
