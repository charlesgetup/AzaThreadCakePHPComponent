AzaThreadCakePHPComponent
=========================

I build this component to use AzaThread sending campaign emails in a multi-threaded way.

I have modified the original AzaThread project to make sure the process can continue running, not stopped by the exceptions.

It was tested under CakePHP 2.3.6, PHP 5.4.17.

I created this repository only to keep reference of what I have done, and some code in the component is directly from my source code.

I have made some comments about the code which only works in my system. If anyone wants to use this code, people can keep my logic and replace my code with yours.

The code is not perfect due to my limited knowledge about posix and event loop. If you have ideas about how to implement multi-threaded process in PHP, please feel free to discuss it with me over email, and we can learn this from each other.

Cheers.