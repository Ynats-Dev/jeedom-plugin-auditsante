---
layout: default
lang: it_IT
---

# Changelog Audit Santé

# 26/11/2026 - 0.1.1

- Riferimenti non trovati: un numero attaccato a del testo o che inizia con zero (identificativo hardware come `0B11002F#ID#03020#slider#00` nei comandi di un variatore) non viene più scambiato per un comando eliminato.
- Pulsante «Escludi» esteso ai plugin disattivati, ai dispositivi muti, orfani o in timeout, agli oggetti vuoti e alle viste e ai design vuoti (sensore che cambia raramente, dispositivo stagionale, dispositivo letto dall'API, stanza preparata in anticipo...).
- Scenari e dispositivi disattivati volutamente: pulsante «Escludi» su sei controlli (scenari che non possono mai avviarsi, che non si avviano più, che usano elementi disattivati, attivati da un dispositivo disattivato, scenari disattivati dimenticati, dispositivi disattivati). L'elemento resta elencato a parte, non conta più nel punteggio e si reintegra con un clic.
- Dispositivi e comandi collegati a elementi eliminati: posizione del riferimento (azione prima o dopo l'esecuzione, azione su valore, valore collegato...), apertura diretta della configurazione avanzata del comando e pulsante che rimuove l'azione orfana tramite Jeedom.
- Comando informazione: un'azione prima o dopo l'esecuzione, che Jeedom non mostra su questo tipo di comando, viene segnalata come nascosta, con la procedura (salvare il comando senza modificare nulla, o il pulsante «Elimina il valore info orfano»).
- Dispositivi fantasma: correzione automatica che elimina i dispositivi dei plugin non più installati, con i loro comandi e la loro cronologia. Quelli dei plugin disattivati non vengono toccati.
- Scenari che usano elementi eliminati: ogni riferimento indica la sua posizione (trigger, condizione o azione di un blocco, e «nelle sue opzioni» se si trova in un campo dell'azione).
- I trigger di uno scenario in modalità Programmato, che Jeedom conserva nel database ma nasconde e non usa, non vengono più controllati.
- Un numero maggiore dell'ultimo identificativo assegnato da Jeedom (ID di conversazione Telegram, numero di telefono...) non viene più segnalato come riferimento non trovato.
- Resti di plugin: vengono mostrati i nomi dei parametri e spiegate le configurazioni salvate con un percorso di file o senza nome di plugin («false», «null»).

# 24/11/2026 - 0.1.0

- Prima versione: 42 controlli in 7 categorie (riferimenti interrotti, scenari, dispositivi, comandi e cronologia, oggetti/viste/design, plugin e attività, database e file).
- Indice «chi usa cosa» creato con una sola lettura del database.
- Link alla pagina Jeedom di ogni elemento segnalato.
- Archivio dei nomi: rilevazione oraria del nome di ogni elemento, lettura unica dei backup (e di cartelle aggiuntive) per le eliminazioni più vecchie, periodo di eliminazione. Lettura in background con avanzamento, avviata all'installazione, prima del primo audit (che viene avviato dall'utente).
- Correzioni automatiche limitate agli elementi che nulla può più usare.
- Dati del plugin in file JSON (`data/`), indipendenti dal database di Jeedom.
- Punteggio, lettera, controlli ignorati per una durata, commenti, rapporto di diagnostica, dispositivo utilizzabile negli scenari.
- Interfaccia e documentazione illustrata in francese, inglese, tedesco, spagnolo e italiano.
- Invio di un backup dal computer (.tar.gz, .tgz, .tar o .zip), senza dare accesso a una cartella.
