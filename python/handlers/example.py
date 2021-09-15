import urllib3
from multiprocessing.dummy import Pool as ThreadPool
import pandas as pd

trades = pd.read_csv('E:/Jupyter_notebook/trading/finished_datasets/trades_all.csv')

def set_price_direction(df):
	df = df.astype('float32')
	
	price_arr = df[['price']].values
	time_arr = df[['time']].values
	qty_arr = df[['qty']].values
	quoteQty_arr = df[['quoteQty']].values
	isBuyerMaker_arr = df[['isBuyerMaker']].values
	id_arr = df[['id']].values
	step = 60 * 3 * 1000  # в мс.
	
	finishData = []
	for i in range(0, 100):
		
		if i % 500 == 0:
			print('Завершено на ' + str(i / len(df) * 100) + '%')
		
		priceDirection = None
		priceDiff = None
		priceDiffPercent = None
		
		currentTime = int(time_arr[i][0])
		currentPrice = price_arr[i][0]
		qty = qty_arr[i][0]
		quoteQty = quoteQty_arr[i][0]
		isBuyerMaker = isBuyerMaker_arr[i][0]
		currentId = int(id_arr[i][0])
		
		if isBuyerMaker:
			is_sale = 1
		else:
			is_sale = 0
		
		try:
			findPrice = price_arr[time_arr > currentTime + step][0]
			priceDiff = abs(currentPrice - findPrice)
			if findPrice > currentPrice:
				priceDirection = 1
			else:
				priceDirection = 0
			priceDiffPercent = priceDiff / currentPrice * 100
		except IndexError:
			findPrice = None
		
		finishData.append(
			[currentId, currentTime, currentPrice, qty, quoteQty, priceDirection, priceDiff, priceDiffPercent, is_sale])
	
	return pd.DataFrame(finishData, columns=['id', 'time', 'price', 'qty', 'quoteQty', 'priceDirection', 'priceDiff',
	                                         'priceDiffPercent', 'is_sale'])

# Make the Pool of workers
pool = ThreadPool(4)

# Open the URLs in their own threads
# and return the results
results = pool.map(set_price_direction, trades.iloc[500:1000])

# Close the pool and wait for the work to finish
pool.close()
pool.join()

print(results)