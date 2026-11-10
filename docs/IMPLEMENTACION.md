# Kardex de mantenimiento

Aplicación Laravel 12 para clientes, proyectos, equipos tecnológicos e intervenciones preventivas/correctivas. Interfaz en español, adaptable a escritorio y celular.

## Decisiones acordadas

- El equipo tiene identidad única (marca, modelo, serie y código).
- Un cliente tiene varias ubicaciones reutilizables: sede, piso y área.
- Cada equipo asignado a un proyecto recibe un número automático 001, 002, etc. El correlativo se reinicia por proyecto.
- Cada visita genera una intervención independiente. Preventivo y correctivo comparten el número de Kardex del equipo y tienen correlativo de intervención propio.
- Las plantillas del proyecto definen campos técnicos por tipo de equipo y listas de actividades preventivas/correctivas. Pueden copiarse entre proyectos y editarse.
- Los campos admiten texto, número, fecha y lista, con unidad y obligatoriedad. Se pueden agregar características al registrar un equipo.
- Las actividades admiten pendiente, realizado, no realizado y no aplica. Cada actividad conserva responsable, fecha, procedimiento y observaciones.
- Fotos opcionales por actividad, múltiples, con descripción y momento (antes/durante/después). Se almacenan en disco privado y requieren autorización por proyecto.
- Las mediciones pertenecen a la visita, no a la ficha técnica. Los repuestos admiten cambiado, pendiente y no aplica.
- Flujo: borrador → revisión → finalizado. Supervisor/administrador pueden devolver a borrador. Solo administrador reabre un finalizado, indicando motivo.
- La finalización conserva datos del equipo, proyecto, ubicación, actividades y contenido de la intervención. El historial registra la copia anterior al reabrir.
- Dos responsables de firma: fiscal/encargado y agente de servicio. No necesitan cuenta.

## Roles

| Rol | Acceso |
| --- | --- |
| Administrador | Configuración general, personal, asignaciones y todas las intervenciones; reapertura |
| Supervisor | Proyectos asignados, registro, revisión, devolución y finalización |
| Técnico | Proyectos asignados, creación de intervenciones, datos, actividades, fotos y envío a revisión |
| Consulta | Lectura de proyectos asignados y avances; impresión de finalizados |

Las cuentas se crean por un administrador, no mediante registro público. Se exige cambiar la contraseña temporal. Desactivar conserva el historial y revoca el acceso en la siguiente solicitud.

## Arranque local

La instalación actual usa SQLite, según el `.env` existente. No se modificó la conexión.

```powershell
php artisan migrate
.\serve.ps1
```

`serve.ps1` inicia el servidor en `127.0.0.1:8000` y utiliza `storage/framework/php-uploads` para los archivos temporales de PHP. Así se evita que un directorio temporal de XAMPP sin permisos interrumpa las respuestas de carga. Puede usarse `-Port 8001` para otro puerto.

Para crear otra cuenta administradora:

```powershell
php artisan kardex:admin tu-correo@empresa.com --name="Tu nombre"
```

El comando genera una contraseña temporal aleatoria. No se incluyen contraseñas en este documento ni en semillas.

Primer recorrido: ingresar → cambiar contraseña → Administración → crear cliente y ubicación → crear proyecto → configurar plantilla → agregar equipo → asignar usuarios → crear intervención → completar actividades y datos → enviar a revisión → finalizar.

## Impresión

La vista «Imprimir / PDF» genera el documento con las secciones correspondientes y anexo fotográfico. La descarga PDF se realiza mediante «Guardar como PDF» del navegador. Los borradores se identifican como documentos no finalizados. No se implementó todavía un motor PDF de servidor ni descarga masiva.

## Verificación

```powershell
php artisan test
php artisan view:cache
php vendor/bin/pint app routes database/migrations/2026_10_17_000001_create_kardex_tables.php tests/Feature/KardexTest.php
```

`tests/browser-smoke.cjs` ejecuta un recorrido de navegador usando una base SQLite separada en `tmp/browser`, sin agregar datos ficticios a la base de trabajo. Su configuración actual utiliza las dependencias locales de Codex y Microsoft Edge.

## Alcance de esta primera versión

Se implementó el recorrido principal de registro y cierre. Quedan como mejoras posteriores: programación y alertas de próximos mantenimientos, importación desde Excel, personalización de logos, exportación masiva, edición completa de catálogos y búsqueda/paginación para grandes volúmenes. El acceso desde otros dispositivos necesita configurar el servidor y la red; el servidor actual escucha solo en esta computadora.

Los reportes de avance cuentan actividades realizadas sobre actividades aplicables; «no realizado» permanece como trabajo no ejecutado y «no aplica» se excluye. Se permite cerrar con actividades no realizadas explícitamente documentadas, sujeto a revisión del supervisor.

## Administración con Livewire

La pantalla de Administración usa Livewire 4.4 con pestañas para Clientes, Ubicaciones, Proyectos y Usuarios. Permite crear y editar mediante modales centrados, validación por campo, búsqueda al escribir, filtro por cliente y paginación sin recargar la página. Las listas y contadores se actualizan inmediatamente después de guardar y consultan cambios externos cada 15 segundos mientras la pantalla está visible (no utiliza WebSockets).

Cada petición de Livewire verifica cuenta activa, contraseña cambiada y rol administrador. El ID de edición y el tipo de formulario están bloqueados contra cambios del navegador. No se permite modificar el acceso propio ni trasladar a otro cliente un proyecto o ubicación con equipos asociados. Los datos existentes no se migran ni eliminan.

Las demás pantallas conservan su flujo anterior. La conversión a Livewire de esta entrega corresponde a la administración.

### Flujo guiado de administración

La pantalla explica la diferencia entre cliente, proyecto, ubicación y usuario. El acceso «Crear proyecto paso a paso» abre un modal con tres pasos: cliente, servicio y revisión. Permite seleccionar un cliente existente o introducir uno nuevo; el nuevo cliente y el proyecto se guardan juntos en una transacción al confirmar, y cancelar no crea registros parciales. Cada paso valida sus propios campos y permite volver atrás conservando los datos.

Los formularios muestran ejemplos, campos opcionales y explicación del rol elegido. Al guardar aparecen acciones de continuación contextual: registrar un proyecto para el cliente, agregar una ubicación, configurar el proyecto o asignar usuarios desde el proyecto. Los controles de búsqueda, edición y sincronización de Livewire se mantienen.

### Presentación compacta (vigente)

Se retiraron la guía inicial, descripciones de secciones y ayudas repetidas. Administración muestra pestañas, búsqueda, filtros y alta en una barra compacta; los modales conservan etiquetas y validación, con ayuda breve solo para permisos y contraseñas. Se mantiene el asistente de proyecto, sin textos largos. Se redujeron espaciados de tarjetas, tablas y formularios del sistema, y se versionaron CSS/JS para evitar estilos antiguos en caché.

### Ubicación manual y opcional (vigente)

Se retiró Ubicaciones de Administración y el selector obligatorio al registrar equipos. Cada intervención tiene `location_text`, editable por el técnico mientras esté en borrador. Puede dejarse vacío; en ese caso se omite del PDF. La migración copia las ubicaciones anteriores a las intervenciones existentes y conserva los snapshots finalizados. El catálogo y vínculo anteriores permanecen únicamente como datos históricos, sin rutas para crear nuevas ubicaciones.

### Proyecto compacto con Livewire

El proyecto usa ProjectWorkspace: pestañas Tipos de equipo, Equipos y Kardex, y Personal. El siguiente paso depende de la existencia de tipos y equipos. Crear/editar/copiar tipos, asignar personal, agregar equipos y crear intervenciones se abren en modales. El término visible «Tipo de equipo» corresponde a una plantilla específica del proyecto. Los permisos se verifican en cada acción y las relaciones se restringen al proyecto.
