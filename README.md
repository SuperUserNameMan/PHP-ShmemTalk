# PHP-ShmemTalk
PHP8.x synchronized shared-memory array that can be used as IPC between two PHP 8.x processes.


# WHAT ??

This class allows two PHP 8.x processes to communicate using a synchronized shared memory array.

The main PHP process uses this class to launches a separate background PHP process.

A php array is synchronized between the two and allow them to talk the way you want.

It works on Windows and Linux.

# WHY ???

I'm too lazy to explain in details right now, but if you encountered the same frustrating issues than me regarding multi-threading and non blocking IPC on PHP 8.x CLI under Windows and Linux, and if the single idea of trying to think about installing the Microsoft PHP for Windows toolchain in the hope of compiling all you need yourself on Windows gives you nosea and instestinal transit issues, you might find this library useful or inspiring ...

# HOW ???

- on Windows, the API of the misssing extension `sysvsem` is emulated using [PHP-Win32-Semaphore](https://github.com/SuperUserNameMan/PHP-Win32-Semaphore) which itself requires extension `FFI` to bind to the required Win32 API ;
- `popen` is used to launch background child PHP process ;
- a non blocking synchronizsation mechanism allows them to exchange data through a PHP array ;
