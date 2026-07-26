# JahechaCine

> App Android para explorar catálogo de películas y series con datos de TMDB.
> Primera versión del proyecto; su sucesora es [Nexus](../nexus).

| | |
| --- | --- |
| **Rol** | Desarrollo Android |
| **Periodo** | 2026 |
| **Tipo** | Proyecto propio — **discontinuado**, sustituido por [Nexus](../nexus) |
| **Stack** | Java 11 · Android SDK 36 · Retrofit · Glide · Navigation Component |
| **Volumen** | ~580 líneas de Java · minSdk 24 |

## Contexto

Primer acercamiento a construir una app de visualización de contenido en Android.
El objetivo era resolver lo básico de punta a punta: consumir una API de catálogo,
pintar una cuadrícula de resultados, abrir la ficha de un título y montar la
pantalla de reproducción.

*Jahecha* significa «mirar» o «ver» en guaraní.

## Qué construí

Aplicación con vistas clásicas de Android —XML de layout, `RecyclerView`,
Activities y un Fragment— sobre la API de **TMDB**:

- `SplashActivity` con animación de entrada, `MainActivity` como contenedor de
  navegación y `HomeFragment` con la cuadrícula del catálogo.
- `MediaAdapter` sobre `RecyclerView` para la lista, con carga de carátulas
  mediante **Glide**.
- `TmdbClient` con **Retrofit** y **OkHttp**, inyectando la clave de API como
  parámetro de consulta desde un interceptor, con `HttpLoggingInterceptor` para
  depurar las llamadas.
- `DetailActivity` para la ficha del título y `PlayerActivity` para la
  reproducción.
- Tema oscuro con `values-night` y transiciones propias entre pantallas.

## Por qué se discontinuó

El proyecto llegó a mostrar catálogo y ficha, pero se detuvo antes de resolver la
parte realmente difícil: **de dónde sale el vídeo**. TMDB da metadatos —qué
películas existen, sus carátulas y sinopsis— pero no fuentes de reproducción.

Las limitaciones que empujaron a reescribir en lugar de continuar:

- **Una sola fuente de datos.** Todo el modelo asumía TMDB. Añadir catálogos de
  anime, con otro esquema y otros identificadores, obligaba a tocar todas las
  capas.
- **Sin capa de fuentes.** No había ninguna abstracción sobre «dónde se ve» un
  título; la pantalla de reproducción esperaba una URL que nadie producía.
- **Java con Activities y vistas XML.** Cada pantalla nueva costaba mucho boilerplate,
  y el estado se manejaba a mano entre `Activity` y `Fragment`.

De ese diagnóstico salió [Nexus](../nexus): Kotlin y Compose en lugar de Java y XML,
una interfaz `SourceConnector` que abstrae el problema de las fuentes, y varios
proveedores de metadatos en paralelo en vez de uno solo.

## Herramientas utilizadas

| Herramienta | Para qué |
| --- | --- |
| **Java 11** | Lenguaje |
| **Android SDK 36** (minSdk 24) | Plataforma |
| **Retrofit** + Gson | Cliente REST y deserialización |
| **OkHttp** + LoggingInterceptor | HTTP e inspección de llamadas |
| **Glide** | Carga y caché de carátulas |
| **Navigation Component** | Navegación entre destinos |
| **Material Components** + ConstraintLayout | Interfaz |
| **API de TMDB** | Catálogo de películas y series |
| **JUnit** + Espresso | Andamiaje de pruebas (sin cobertura real) |
| **Gradle 8** con Version Catalog | Compilación |
| **Claude Code** y **Codex** | Asistencia en desarrollo |

## Valor en el portafolio

Se conserva a propósito. Puesto junto a Nexus muestra un ciclo de decisión
completo: qué se construyó primero, qué límites aparecieron al usarlo y qué se
cambió en la segunda versión por esos límites concretos. Esa comparación dice más
sobre criterio técnico que cualquiera de las dos apps por separado.

## Compilar

```bash
./gradlew :app:assembleDebug
```

Requiere una clave de la API de TMDB en `TmdbClient.java` (`API_KEY`), que en el
repositorio está como marcador.

## Pendiente de completar

- [ ] Captura del catálogo y de la ficha, para el contraste visual con Nexus
