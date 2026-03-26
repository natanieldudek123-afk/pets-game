<?php
// Ten plik mówi serwerowi Home.pl, co ma uruchamiać
// Składnia: minuta godzina dzień miesiąc dzień_tygodnia ścieżka_do_pliku

echo "*/5 * * * * /gra1/app/cron/tick.php";
