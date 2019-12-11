# /**
#  * Sentiment Analysis Task
#  *
#  * This python script runs sentiment analysis on a set of text files
#  * containing online text assignment submissions. Two output files
#  * are generated, a .pdf file, and a .csv file. The .pdf file has a
#  * page for each submission, with its polarity, subjectivity, plus a
#  * phrase-list. The first page is the collective sentiment assignment
#  * analysis. The .csv contains a line with four fields for each
#  * submission with the assignment name, user name, polarity, and
#  * subjectivity.
#  *
#  * @author      Kara Beason <beasonke@appstate.edu>
#  * @copyright   (c) 2019 Appalachian State University, Boone, NC
#  * @license     GNU General Public License version 3
#  */

import codecs
import csv
import glob
import os
import sys
from reportlab.lib.colors import HexColor
from reportlab.lib.pagesizes import LETTER
from reportlab.pdfgen import canvas
from textblob import TextBlob


# Constants --------------------------------------------------

STD_MARGIN  = 100
STD_INDENT  = 125
LINE_HEIGHT = 18
HEADER_POS  = 225
TOP_OF_PAGE = LETTER[1] - STD_MARGIN
COLOR_BLACK = HexColor('#000000')
COLOR_RED   = HexColor('#FF0000')
COLOR_GREEN = HexColor('#008000')
COLOR_GRAY  = HexColor('#808080')


# Functions --------------------------------------------------

# Determine whether the polarity score (integer passed in)
# is negative (green), neutral (grey), or positive (green)
def get_polarity_color(polarity):
    if (polarity < -0.05):
        return COLOR_RED
    elif (polarity > 0.05):
        return COLOR_GREEN
    else:
        return COLOR_GRAY
# get_polarity_color


# Build the output reports
def build_reports(assignName, collectiveBlob, submissionDict):

    pdfFile = canvas.Canvas("output.pdf", pagesize = LETTER)

    # Print the overall sentiment analysis on the first page.
    y  = TOP_OF_PAGE; pdfFile.drawString(STD_MARGIN, y, "Assignment: " + assignName)
    y -= LINE_HEIGHT; pdfFile.drawString(STD_MARGIN, y, "Overall Sentiment: ")

    y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, "Polarity:")
    pdfFile.setFillColor(get_polarity_color(collectiveBlob.polarity))
    y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, str(collectiveBlob.polarity))
    pdfFile.setFillColor(COLOR_BLACK)

    y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, "Subjectivity:")
    y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, str(collectiveBlob.subjectivity))
    y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, "Green=Positive, Grey=Neutral, Red=Negative")
    y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, "Subjectivity: Scale 0=Objective, 1=Very Subjective ")

    # Close current page.
    pdfFile.showPage()

    # Create list for output to csv.
    csvList = [ [ "assignment", "user", "polarity", "subjectivity" ] ]

    # Sentiment Analysis by text file/student, each student
    # on their own page
    for name, blob in submissionDict.iteritems():

        y  = TOP_OF_PAGE; pdfFile.drawString(STD_MARGIN, y, "Student Name: {}".format(name))
        y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, "")
        y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, "Polarity:")

        pdfFile.setFillColor(get_polarity_color(blob.polarity))
        y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, str(blob.polarity))
        pdfFile.setFillColor(COLOR_BLACK)

        y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, "Subjectivity:")
        y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, str(blob.subjectivity))
        y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, "Green=Positive, Grey=Neutral, Red=Negative")
        y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, "Subjectivity: Scale 0=Objective, 1=Very Subjective")
        y -= LINE_HEIGHT; pdfFile.drawString(STD_INDENT, y, "Assessments:")
        y -= LINE_HEIGHT

        for word in blob.sentiment_assessments.assessments:
            y -= LINE_HEIGHT
            if (y < STD_MARGIN):
                pdfFile.showPage()
                y  = TOP_OF_PAGE; pdfFile.drawString(STD_MARGIN, y, "Student Name: {} continued..".format(name))
                y -= 2 * LINE_HEIGHT

            pdfFile.drawString(STD_INDENT, y, str(word))
        # for word ...

        pdfFile.showPage()
        csvList.append([ assignName, name, blob.polarity, blob.subjectivity ])
    # for name, blob...

    pdfFile.save()

    with open('output.csv','w') as csvFile:
        writer = csv.writer(csvFile)
        writer.writerows(csvList)
# build_reports


# ------------------------------ Execution begins here ------------------------------


# Check that a command line argument is present.
if (len(sys.argv) != 2):
    print("Usage: python sentiments_analysis.py <directory>")
    exit(1)

# Check that the command line argurment is indeed a
# valid directory, and make that the working dir
if (not os.path.isdir(sys.argv[1])):
    print "Directory not valid: {}".format(sys.argv[1])
    exit(1)

os.chdir(sys.argv[1])

if (not os.path.isfile('collective.txt')):
    print "Input file not found in directory: {}".format(sys.argv[1])
    exit(1)

# Expect to find any number of text files in this dir named [n].txt
# where [n] is the unique submission id value. The first line of each
# submission file will be the associated username, the second is the
# user's formatted [lastname, firstname], and the remaining lines are
# the online text submission. Also present in the dir is a file named
# collective.txt, the first line of which has the assignment name, and
# remaining lines are the the collective submissions.

# Start with the collective file
with codecs.open('collective.txt', 'r', 'utf-8', 'strict') as infile:
    lines = infile.readlines()
assignName = lines.pop(0).rstrip('\n')
collectiveBlob = TextBlob(str().join(lines).strip())

# Now the individual submissions. Store them in a dictionary keyed by
# the formatted name plus username
submissionDict = {}
for filename in glob.iglob('[0-9]*.txt'):
    with codecs.open(filename, 'r', 'utf-8', 'strict') as infile:
        lines = infile.readlines()
    username = lines.pop(0).rstrip('\n')
    formattedName = lines.pop(0).rstrip('\n')
    submissionDict["{0} ({1})".format(formattedName, username)] = TextBlob(str().join(lines).strip())

# Build the .pdf, and .csv report files
build_reports(assignName, collectiveBlob, submissionDict)

exit(0)
