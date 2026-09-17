# Pablo Reinaldo De Jesús González Cáceres

Final-year Computer Engineering student · Backend and Android Developer

<!--
  Contact details are NOT in this file: the repository is public.
  They live in cv/datos-contacto.md, which is gitignored, and get injected
  when building the PDF and DOCX.
-->
San Lorenzo, Paraguay · [GitHub](https://github.com/Pablo14K)

## Professional Summary

Final-year Computer Engineering student, six months from graduating. I build
electronic invoicing and management systems in PHP, Laravel and MySQL, and Android
applications in Kotlin.

I work well where requirements are strict and verifiable: I implemented Paraguay's
SIFEN electronic invoicing regime —CDC, XML conforming to the official XSD, XMLDSig
signing and KuDE— on plain PHP with no external dependencies, so it could be
deployed on shared hosting. Where no library was available, I wrote what was
missing: an ISO/IEC 18004 QR encoder, a PDF generator and an SMTP client.

I apply the same standard to management software: my largest project is a
multi-branch Laravel application running in production on Docker, with business logic
in the database, pessimistic locking for concurrency control, and 240 automated tests
that run against a real database.

I am looking for a web or mobile development role where I can work across the whole
stack —database, server logic and interface— and keep growing as a developer.

## Technical Skills

- **Languages:** PHP 8.3, Kotlin, Java, SQL, JavaScript, HTML5, CSS
- **Backend frameworks:** Laravel 13 — service-layer architecture, authorisation
  middleware, Blade, task scheduler, PHPUnit
- **Databases:** MySQL / MariaDB — normalised modelling (3NF), stored procedures,
  functions, triggers, views and CHECK constraints; business logic in the engine.
  Transactions and pessimistic locking (SELECT FOR UPDATE) for concurrency control.
  Room on Android
- **Testing:** PHPUnit against a real database, concurrency tests with parallel
  processes, structural integrity tests
- **Android:** Jetpack Compose, Material 3, Media3/ExoPlayer, Navigation, DataStore,
  WorkManager, Coroutines and Flow, androidx.tv for Android TV
- **Integrations and protocols:** SOAP, XML/XSD, REST, socket-level SMTP, MIME,
  scraping with Jsoup, DNS-over-HTTPS
- **Applied cryptography:** XMLDSig, RSA-SHA256, X.509 and P12/PEM certificates,
  OpenSSL, WebAuthn/FIDO2 (CBOR, COSE, ASN.1/DER)
- **Frontend:** Bootstrap 5, vanilla JavaScript, WCAG accessibility (contrast,
  no-JavaScript fallbacks, responsive design)
- **Infrastructure:** Docker and Docker Compose, Caddy, Traefik, Apache, php-fpm,
  OPcache, cPanel, cron, Gradle, VPS and shared hosting deployment
- **Domain:** SIFEN v150 electronic invoicing (Paraguayan tax authority)
- **Tools:** Git, Android Studio, Claude Code, Codex, Antigravity

**AI-assisted development.** I use Claude Code, Codex and Antigravity as a regular
part of my workflow: to speed up implementation, review code and write documentation.
Architecture decisions, the data model and verification are mine, and anything
generated goes through the test suite before it lands.

## Projects

### SIFEN — Electronic Invoicing System (2026)
Full electronic invoicing system for Paraguay's tax authority. I rewrote the engine
to remove a Node.js microservice, leaving it 100% PHP and deployable on cPanel. It
includes a custom ISO/IEC 18004 QR encoder (Reed-Solomon over GF(256), verified
against Python's `qrcode` library), a PDF generator implementing the pagination rules
of the v150 Technical Manual, XMLDSig signing with RSA-SHA256 and X.509, and a queue
system that resumes interrupted deliveries without duplicating documents.
~6,500 lines of PHP.

### Hair Salon Management System (2026, in production)
Multi-branch Laravel 13 management web application —scheduling, clients, inventory,
cash register, electronic invoicing and a customer self-service portal— deployed in
production on Docker on a VPS with php-fpm, Caddy and HTTPS. Business logic lives in
the database: 85 tables in 3NF, 51 functions, 22 stored procedures, 17 triggers, 18
views and 93 CHECK constraints, consumed through a dedicated service layer rather than
reimplemented in the ORM. It handles splitting one appointment across several
professionals and several people, with pessimistic locking so two simultaneous
bookings on the same slot leave exactly one appointment. It issues electronic invoices
and credit notes to the Paraguayan tax authority. Includes biometric WebAuthn login
written from scratch (CBOR decoder, COSE keys and ASN.1/DER in PHP) and a theming
engine that derives 42 colour tokens from a single chosen colour, verifying WCAG
contrast. 240 automated tests, including concurrency tests with parallel processes.
I migrated the system from framework-less PHP to Laravel 13 while preserving the data
model. Final-year thesis project, in a pair.
→ [Repository](https://github.com/Pablo14K/SPG)

### Nexus — Android App (2026, in development)
Android application in Kotlin and Jetpack Compose, sharing one codebase across phone
and Android TV. It aggregates three metadata providers in parallel and resolves
playback sources as a stream, emitting each result as it arrives rather than waiting
for the slowest. The player falls through alternative links automatically and detects
a failure ExoPlayer never reports —H.264 10-bit video that silently fails to
render— by falling back to libVLC. ~10,700 lines of Kotlin.

### SIFEN Automator (2026)
A stripped-down version of the system above: the same tax engine with no interface
and no database, so a business can issue electronic invoices without changing the
software it already runs. Integration is a folder where it drops text files. Runs as
a cron job, a watcher process or a Docker container, and is integrated in production
with the Hair Salon Management System, for which the input format was extended while
keeping backward compatibility with earlier integrators.

## Work Experience

### Programmer (Internship), Vieloy Sistemas
April 2026 – May 2026 | Paraguay

- Built the SIFEN electronic invoicing system commissioned by the company: XML
  generation following the tax authority's v150 Technical Manual, XMLDSig digital
  signing and KuDE graphical representation, in PHP with no external dependencies.
- The company later dropped the project and authorised me to keep it, so I continued
  its development independently until it was functional.

## Education

### Computer Engineering, Universidad Columbia del Paraguay
Fifth year, in progress | Expected graduation early 2027

Final-year thesis in development: "Web management system for a hair salon in Luque"
— Research line: Computer Systems. The system is deployed and running in production.

## Languages

- **Spanish:** native
- **Guaraní:** basic
- **English:** basic — reads technical documentation. Taken as a university subject,
  no external certification.
