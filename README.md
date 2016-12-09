# A Dockerized LAMP web-app - upload files and share links

This is a simple LAMP stack web application that enables one to upload files, store the uploaded files encrypted-at-rest, and share links for downloading. These links expire in a day.

The primary point of this application is not to show how to write a file-sharing application with Apache and PHP - hence the code is simple, it does not have bells and whistles. This is just an example functionality - based on which, my goal is to have an architectural discussion on how such an application could be run at scale using docker containers - securly, catering to millions of users. This application does not use a database, but I hope to shed some light on all such aspects using this code as the starting point.

## How to run and test this app

First things first. These instructions will get you a copy of the project up and running on your local machine or any VM running on AWS or Azure or anywhere else - as long as it is running docker.

### Set up your docker host

To run this code, you need a Linux host running Docker. It does not matter how you get one or where you are running it. I will not go into the details of how to get one up and running, but there are instruction sets readily available for [AWS](http://docs.aws.amazon.com/AmazonECS/latest/developerguide/docker-basics.html) or [Azure](https://docs.microsoft.com/en-us/azure/virtual-machines/virtual-machines-linux-dockerextension).

### Get the code and run it

Once you are logged into a Linux host running Docker, clone this repository, and cd into file-upload-link-share-simple directory

```
XXXXX@MyDockerVM:~/file-upload-link-share-simple$ ls -l
total 12
drwxrwxr-x 2 Azure123 Azure123 4096 Dec  9 01:45 code
-rw-rw-r-- 1 Azure123 Azure123  249 Dec  9 01:45 Dockerfile
-rw-rw-r-- 1 Azure123 Azure123  133 Dec  9 01:45 README.md
```

Check if docker daemon is running, and if it is, build the docker image for this application (you can tag your image anything you want, I have tagged it "koushik/fileuploadsimple"

```
sudo docker build -t koushik/fileuploadsimple .
```
